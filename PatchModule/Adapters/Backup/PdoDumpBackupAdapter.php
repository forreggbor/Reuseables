<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Built-in, exec-free database backup adapter for PatchModule.
 */

namespace PatchModule\Adapters\Backup;

use PatchModule\Contracts\BackupAdapterHealthInterface;
use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\LoggerInterface;
use PDO;

/**
 * Implements BackupAdapterInterface with nothing but the module's own PDO connection.
 *
 * Needs no database credentials, no exec() and no external binaries, so nothing
 * secret can show up in a process list and it works on shared hosting with the
 * process functions disabled. The whole current database is written to one
 * gzip-compressed JSON-lines file: table definitions, rows streamed in bounded
 * chunks, views, routines, events and triggers. Restore replays that file through
 * PDO with prepared statements, so no SQL text has to be split or re-quoted.
 *
 * The file is an internal format, not a mysqldump-compatible .sql file. Very large
 * databases, or databases using system-versioned tables or sequences, should keep
 * using MysqldumpBackupAdapter. The patch_backups bookkeeping table is created on
 * first use and is never part of a dump, so a restore cannot remove the record of
 * the backup it is restoring from.
 *
 * Session settings that influence data round-trips (sql_mode, time zone, charset,
 * foreign key checks) are changed for the duration of a call and put back after it.
 */
class PdoDumpBackupAdapter implements BackupAdapterInterface, BackupAdapterHealthInterface
{
    /** Dump format identifier written to the header record. */
    private const FORMAT = 'patchmodule-pdo-dump';

    /** Dump format version; restore refuses files with a higher version. */
    private const FORMAT_VERSION = 1;

    /** Bookkeeping table shared with MysqldumpBackupAdapter; never dumped. */
    private const BACKUP_TABLE = 'patch_backups';

    /** Maximum rows per `rows` record. */
    private const ROWS_PER_CHUNK = 500;

    /** Approximate maximum payload bytes per `rows` record. */
    private const CHUNK_BYTES = 1048576;

    /** Maximum bound parameters per INSERT statement on restore (server limit is 65535). */
    private const INSERT_PARAM_LIMIT = 60000;

    /** DDL for patch_backups — keep in sync with schema/patch_backups.sql */
    private const BACKUP_TABLE_DDL = "
        CREATE TABLE IF NOT EXISTS `patch_backups` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `filename`   VARCHAR(255)         NOT NULL,
            `file_size`  BIGINT UNSIGNED      DEFAULT 0,
            `note`       VARCHAR(500)         DEFAULT NULL,
            `created_at` DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT UNSIGNED         DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    /** @var DatabaseAdapterInterface Source of the (lazily created) PDO connection. */
    private DatabaseAdapterInterface $database;

    /** @var string Directory where backup files are stored. */
    private string $backupDir;

    /** @var LoggerInterface|null Optional logger. */
    private ?LoggerInterface $logger;

    /** @var bool Instance-local latch: true once the patch_backups table is known to exist. */
    private bool $tableReady = false;

    /**
     * @param DatabaseAdapterInterface $database  Database adapter; its PDO is created on first use.
     * @param string                   $backupDir Directory for backup files; created on first backup.
     * @param LoggerInterface|null     $logger    Optional logger.
     *
     * @throws \InvalidArgumentException If the backup directory is empty.
     */
    public function __construct(
        DatabaseAdapterInterface $database,
        string $backupDir,
        ?LoggerInterface $logger = null
    ) {
        $backupDir = rtrim($backupDir, '/');
        if ($backupDir === '') {
            throw new \InvalidArgumentException('PdoDumpBackupAdapter requires a non-empty backup directory');
        }

        $this->database  = $database;
        $this->backupDir = $backupDir;
        $this->logger    = $logger;
    }

    /**
     * Check whether the PHP extensions this adapter needs are loaded.
     *
     * @return bool True when pdo_mysql and zlib are available.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('pdo_mysql') && function_exists('gzopen');
    }

    /**
     * {@inheritdoc}
     *
     * Checks the PHP extensions, the backup directory and a trivial query on the module's connection.
     */
    public function checkOperational(): array
    {
        if (!self::isAvailable()) {
            return ['ok' => false, 'reason' => 'The pdo_mysql and zlib PHP extensions are required.'];
        }

        $dirProblem = BackupDirectoryCheck::problem($this->backupDir);
        if ($dirProblem !== null) {
            return ['ok' => false, 'reason' => $dirProblem];
        }

        try {
            if ($this->database->getPdo()->query('SELECT 1') === false) {
                return ['ok' => false, 'reason' => 'The database connection does not answer a trivial query.'];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'The database connection failed: ' . $e->getMessage()];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * {@inheritdoc}
     *
     * Writes the dump to a new file inside a consistent-snapshot transaction (when the
     * connection is not already inside one) and records it in patch_backups. A partial
     * file is removed when anything fails.
     */
    public function createBackup(string $note, ?int $userId = null): array
    {
        $filePath = null;

        try {
            $pdo = $this->connection();
            $this->prepareBackupDir();
            $this->ensureBackupTable($pdo);

            $filename = 'patch_backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.jsonl.gz';
            $filePath = $this->backupDir . '/' . $filename;

            $stats = $this->withSession($pdo, fn (): array => $this->writeDump($pdo, $filePath));

            if (!chmod($filePath, 0640)) {
                $this->log('Built-in backup: could not set permissions on ' . $filename, 'WARNING');
            }

            clearstatcache(true, $filePath);
            $fileSize = (int) filesize($filePath);
            if ($fileSize === 0) {
                throw new \RuntimeException('Backup file is empty after writing');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO `patch_backups` (`filename`, `file_size`, `note`, `created_at`, `created_by`)
                 VALUES (:filename, :file_size, :note, NOW(), :created_by)'
            );
            // The column holds 500 characters; cut on a character boundary without needing mbstring.
            $note = preg_match('/^.{0,500}/su', $note, $cut) === 1 ? $cut[0] : substr($note, 0, 500);
            $stmt->execute([
                ':filename'   => $filename,
                ':file_size'  => $fileSize,
                ':note'       => $note,
                ':created_by' => $userId,
            ]);
            $backupId = (int) $pdo->lastInsertId();

            $this->log(
                "Built-in backup: created #{$backupId} ({$stats['tables']} tables, {$stats['rows']} rows, {$fileSize} bytes)",
                'INFO'
            );

            return ['success' => true, 'backup_id' => $backupId, 'error' => null];
        } catch (\Throwable $e) {
            if ($filePath !== null) {
                $this->removeFile($filePath);
            }
            $this->log('Built-in backup: create failed: ' . $e->getMessage(), 'ERROR');

            return ['success' => false, 'backup_id' => null, 'error' => 'Backup failed: ' . $e->getMessage()];
        }
    }

    /**
     * {@inheritdoc}
     *
     * The whole file is checked first (header, end record, record count), so a truncated
     * or corrupt backup is refused before anything in the database is touched. Tables in
     * the backup are dropped and recreated; objects that exist now but not in the backup
     * are left alone, the same as a mysqldump restore.
     */
    public function restoreDatabase(int $backupId): array
    {
        try {
            $pdo      = $this->connection();
            $record   = $this->findBackup($pdo, $backupId);
            if ($record === null) {
                return ['success' => false, 'error' => 'Backup record not found: ' . $backupId];
            }

            $filePath = $this->backupFilePath((string) $record['filename']);
            if (!is_file($filePath)) {
                return ['success' => false, 'error' => 'Backup file not found: ' . $filePath];
            }

            $this->verifyDumpFile($filePath);
            $this->withSession($pdo, function () use ($pdo, $filePath): void {
                $this->applyDump($pdo, $filePath);
            });

            $this->log("Built-in backup: restored #{$backupId}", 'INFO');

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log("Built-in backup: restore of #{$backupId} failed: " . $e->getMessage(), 'ERROR');

            return ['success' => false, 'error' => 'Restore failed: ' . $e->getMessage()];
        }
    }

    /**
     * {@inheritdoc}
     *
     * Deletes the backup file and then its patch_backups record.
     */
    public function deleteBackup(int $backupId): array
    {
        try {
            $pdo    = $this->connection();
            $record = $this->findBackup($pdo, $backupId);
            if ($record === null) {
                return ['success' => false, 'error' => 'Backup record not found: ' . $backupId];
            }

            $filePath = $this->backupFilePath((string) $record['filename']);
            if (is_file($filePath) && !unlink($filePath)) {
                $this->log('Built-in backup: could not delete file ' . $filePath, 'ERROR');
                return ['success' => false, 'error' => 'Could not delete backup file: ' . $filePath];
            }

            $stmt = $pdo->prepare('DELETE FROM `patch_backups` WHERE `id` = :id');
            $stmt->execute([':id' => $backupId]);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log("Built-in backup: delete of #{$backupId} failed: " . $e->getMessage(), 'ERROR');

            return ['success' => false, 'error' => 'Delete failed: ' . $e->getMessage()];
        }
    }

    /**
     * {@inheritdoc}
     *
     * Measures the nearest existing ancestor of the backup directory, because the
     * preflight check runs before the first backup has created the directory.
     */
    public function getFreeDiskSpace(): int
    {
        $dir = $this->backupDir;
        while (!is_dir($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        $free = disk_free_space($dir);
        if ($free === false) {
            $this->log('Built-in backup: could not read free disk space of ' . $dir, 'WARNING');
            return 0;
        }

        return (int) $free;
    }

    // =========================================================================
    // Dump
    // =========================================================================

    /**
     * Write the complete dump file.
     *
     * @param PDO    $pdo      Active connection (session settings already applied).
     * @param string $filePath Target file; created by this call.
     * @return array{tables: int, rows: int} Dump statistics.
     * @throws \RuntimeException If the file cannot be written or an object type is unsupported.
     */
    private function writeDump(PDO $pdo, string $filePath): array
    {
        $gz = gzopen($filePath, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Cannot open backup file for writing: ' . $filePath);
        }

        $snapshot       = false;
        $records        = 0;
        $tables         = 0;
        $rows           = 0;
        $bufferedAttr   = $this->bufferedQueryAttribute();
        $bufferedBefore = (bool) $pdo->getAttribute($bufferedAttr);

        try {
            // Stream result sets from the server instead of holding a whole table in PHP memory.
            $pdo->setAttribute($bufferedAttr, false);

            if (!$pdo->inTransaction()) {
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
                $snapshot = true;
            } else {
                $this->log('Built-in backup: connection is already inside a transaction, dumping without a snapshot', 'WARNING');
            }

            $this->writeRecord($gz, $records, [
                'k'          => 'header',
                'format'     => self::FORMAT,
                'version'    => self::FORMAT_VERSION,
                'created_at' => gmdate('c'),
                'database'   => (string) $this->select($pdo, 'SELECT DATABASE() AS name')[0]['name'],
                'server'     => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            ]);

            foreach ($this->listObjects($pdo) as $object) {
                if ($object['type'] === 'table') {
                    $rows += $this->dumpTable($pdo, $gz, $records, $object['name']);
                    $tables++;
                } else {
                    $this->dumpView($pdo, $gz, $records, $object['name']);
                }
            }

            foreach ($this->listCodeObjects($pdo) as $object) {
                $this->dumpCodeObject($pdo, $gz, $records, $object['kind'], $object['name']);
            }

            $this->writeRecord($gz, $records, ['k' => 'end', 'records' => $records, 'tables' => $tables, 'rows' => $rows]);
        } finally {
            if ($snapshot) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable $e) {
                    $this->log('Built-in backup: could not end the snapshot transaction: ' . $e->getMessage(), 'WARNING');
                }
            }
            try {
                $pdo->setAttribute($bufferedAttr, $bufferedBefore);
            } catch (\Throwable $e) {
                $this->log('Built-in backup: could not restore the buffered-query setting: ' . $e->getMessage(), 'WARNING');
            }
            if (!gzclose($gz)) {
                $this->log('Built-in backup: gzclose failed for ' . $filePath, 'ERROR');
            }
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * List the tables and views to dump, tables first.
     *
     * @param PDO $pdo Active connection.
     * @return array<int, array{type: string, name: string}> Objects in dump order.
     * @throws \RuntimeException If an unsupported object type (sequence, system-versioned table) exists.
     */
    private function listObjects(PDO $pdo): array
    {
        $rows = $this->select(
            $pdo,
            'SELECT `TABLE_NAME` AS name, `TABLE_TYPE` AS type FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` <> :backup_table
             ORDER BY `TABLE_NAME`',
            [':backup_table' => self::BACKUP_TABLE]
        );

        $tables = [];
        $views  = [];
        foreach ($rows as $row) {
            $type = strtoupper((string) $row['type']);
            if ($type === 'BASE TABLE') {
                $tables[] = ['type' => 'table', 'name' => (string) $row['name']];
            } elseif ($type === 'VIEW') {
                $views[] = ['type' => 'view', 'name' => (string) $row['name']];
            } else {
                throw new \RuntimeException(
                    "Unsupported object type '{$type}' for '{$row['name']}' — use MysqldumpBackupAdapter for this database"
                );
            }
        }

        return array_merge($tables, $views);
    }

    /**
     * List routines, events and triggers to dump.
     *
     * @param PDO $pdo Active connection.
     * @return array<int, array{kind: string, name: string}> Objects in dump order.
     */
    private function listCodeObjects(PDO $pdo): array
    {
        $objects = [];

        $routines = $this->select(
            $pdo,
            'SELECT `ROUTINE_NAME` AS name, `ROUTINE_TYPE` AS type FROM `information_schema`.`ROUTINES`
             WHERE `ROUTINE_SCHEMA` = DATABASE() ORDER BY `ROUTINE_TYPE`, `ROUTINE_NAME`'
        );
        foreach ($routines as $row) {
            $objects[] = ['kind' => strtoupper((string) $row['type']), 'name' => (string) $row['name']];
        }

        $events = $this->select(
            $pdo,
            'SELECT `EVENT_NAME` AS name FROM `information_schema`.`EVENTS`
             WHERE `EVENT_SCHEMA` = DATABASE() ORDER BY `EVENT_NAME`'
        );
        foreach ($events as $row) {
            $objects[] = ['kind' => 'EVENT', 'name' => (string) $row['name']];
        }

        $triggers = $this->select(
            $pdo,
            'SELECT `TRIGGER_NAME` AS name FROM `information_schema`.`TRIGGERS`
             WHERE `TRIGGER_SCHEMA` = DATABASE() ORDER BY `TRIGGER_NAME`'
        );
        foreach ($triggers as $row) {
            $objects[] = ['kind' => 'TRIGGER', 'name' => (string) $row['name']];
        }

        return $objects;
    }

    /**
     * Write one table: its definition followed by its rows in bounded chunks.
     *
     * Generated columns are left out of the row data, because they cannot be inserted.
     *
     * @param PDO      $pdo     Active connection.
     * @param resource $gz      Open gzip stream.
     * @param int      $records Running record counter.
     * @param string   $table   Table name.
     * @return int Number of rows written.
     */
    private function dumpTable(PDO $pdo, $gz, int &$records, string $table): int
    {
        $create = $this->showCreate($pdo, 'TABLE', $table, 'Create Table');
        $this->writeRecord($gz, $records, ['k' => 'table', 'name' => $table, 'ddl' => $create['ddl']]);

        $columns = $this->insertableColumns($pdo, $table);
        if ($columns === []) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', array_map([$this, 'ident'], $columns)) . ' FROM ' . $this->ident($table)
        );

        $total = 0;
        $chunk = [];
        $bytes = 0;

        try {
            $stmt->execute();
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $encoded = [];
                foreach ($row as $cell) {
                    $encoded[] = $this->encodeCell($cell);
                    $bytes    += is_string($cell) ? strlen($cell) : 8;
                }
                $chunk[] = $encoded;
                $total++;

                if (count($chunk) >= self::ROWS_PER_CHUNK || $bytes >= self::CHUNK_BYTES) {
                    $this->writeRecord($gz, $records, ['k' => 'rows', 'table' => $table, 'cols' => $columns, 'rows' => $chunk]);
                    $chunk = [];
                    $bytes = 0;
                }
            }
        } finally {
            $stmt->closeCursor();
        }

        if ($chunk !== []) {
            $this->writeRecord($gz, $records, ['k' => 'rows', 'table' => $table, 'cols' => $columns, 'rows' => $chunk]);
        }

        return $total;
    }

    /**
     * Write one view definition.
     *
     * @param PDO      $pdo     Active connection.
     * @param resource $gz      Open gzip stream.
     * @param int      $records Running record counter.
     * @param string   $view    View name.
     * @return void
     */
    private function dumpView(PDO $pdo, $gz, int &$records, string $view): void
    {
        $create = $this->showCreate($pdo, 'VIEW', $view, 'Create View');
        $this->writeRecord($gz, $records, ['k' => 'view', 'name' => $view, 'ddl' => $create['ddl']]);
    }

    /**
     * Write one routine, event or trigger definition together with its sql_mode.
     *
     * @param PDO      $pdo     Active connection.
     * @param resource $gz      Open gzip stream.
     * @param int      $records Running record counter.
     * @param string   $kind    PROCEDURE, FUNCTION, EVENT or TRIGGER.
     * @param string   $name    Object name.
     * @return void
     */
    private function dumpCodeObject(PDO $pdo, $gz, int &$records, string $kind, string $name): void
    {
        $column = match ($kind) {
            'PROCEDURE' => 'Create Procedure',
            'FUNCTION'  => 'Create Function',
            'EVENT'     => 'Create Event',
            default     => 'SQL Original Statement',
        };

        $create = $this->showCreate($pdo, $kind, $name, $column);
        $this->writeRecord($gz, $records, [
            'k'        => 'code',
            'kind'     => $kind,
            'name'     => $name,
            'sql_mode' => $create['sql_mode'],
            'ddl'      => $create['ddl'],
        ]);
    }

    /**
     * Run SHOW CREATE for one object and return its definition without the DEFINER clause.
     *
     * The DEFINER is dropped so the object is recreated as the connected user, which
     * works without SUPER privileges.
     *
     * @param PDO    $pdo    Active connection.
     * @param string $kind   TABLE, VIEW, PROCEDURE, FUNCTION, EVENT or TRIGGER.
     * @param string $name   Object name.
     * @param string $column Result column holding the definition.
     * @return array{ddl: string, sql_mode: ?string} Definition and the object's sql_mode (if the kind has one).
     * @throws \RuntimeException If the server returned no definition (missing privilege).
     */
    private function showCreate(PDO $pdo, string $kind, string $name, string $column): array
    {
        $stmt = $pdo->query('SHOW CREATE ' . $kind . ' ' . $this->ident($name));
        $row  = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($stmt !== false) {
            $stmt->closeCursor();
        }

        if ($row === false || !isset($row[$column]) || !is_string($row[$column]) || $row[$column] === '') {
            throw new \RuntimeException("Cannot read the definition of {$kind} '{$name}' (missing privilege?)");
        }

        $ddl  = $row[$column];
        $head = (string) preg_replace(
            '/\sDEFINER\s*=\s*(`(?:[^`]|``)+`|\'[^\']*\'|[^\s@]+)@(`(?:[^`]|``)+`|\'[^\']*\'|\S+)/i',
            '',
            substr($ddl, 0, 400),
            1
        );

        return [
            'ddl'      => $head . substr($ddl, 400),
            'sql_mode' => isset($row['sql_mode']) ? (string) $row['sql_mode'] : null,
        ];
    }

    /**
     * Get the writable (non-generated) columns of a table in definition order.
     *
     * @param PDO    $pdo   Active connection.
     * @param string $table Table name.
     * @return string[] Column names.
     */
    private function insertableColumns(PDO $pdo, string $table): array
    {
        $rows = $this->select(
            $pdo,
            'SELECT `COLUMN_NAME` AS name, `EXTRA` AS extra FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :table ORDER BY `ORDINAL_POSITION`',
            [':table' => $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            if (preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string) $row['extra']) === 1) {
                continue;
            }
            $columns[] = (string) $row['name'];
        }

        return $columns;
    }

    /**
     * Convert a fetched cell into its JSON representation.
     *
     * Everything non-null becomes a string so binding it on restore is exact; text that
     * is not valid UTF-8 (binary data) is wrapped as base64.
     *
     * @param mixed $value Fetched cell value.
     * @return string|array{b: string}|null JSON-safe value.
     */
    private function encodeCell(mixed $value): string|array|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return var_export($value, true);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = (string) $value;
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return ['b' => base64_encode($text)];
    }

    /**
     * Append one record to the dump as a single JSON line.
     *
     * @param resource             $gz      Open gzip stream.
     * @param int                  $records Running record counter (incremented).
     * @param array<string, mixed> $record  Record data.
     * @return void
     * @throws \RuntimeException If the write fails (for example the disk is full).
     */
    private function writeRecord($gz, int &$records, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (gzwrite($gz, $line) !== strlen($line)) {
            throw new \RuntimeException('Failed to write to the backup file (disk full?)');
        }
        $records++;
    }

    // =========================================================================
    // Restore
    // =========================================================================

    /**
     * Check a dump file end to end before it is used for a restore.
     *
     * @param string $filePath Absolute path to the dump file.
     * @return void
     * @throws \RuntimeException If the header is wrong or the file is truncated or corrupt.
     */
    private function verifyDumpFile(string $filePath): void
    {
        $count = 0;
        $last  = null;

        foreach ($this->readLines($filePath) as $line) {
            if ($count === 0) {
                try {
                    $header = $this->decodeLine($line);
                } catch (\RuntimeException) {
                    $header = [];
                }
                if (($header['k'] ?? '') !== 'header' || ($header['format'] ?? '') !== self::FORMAT) {
                    throw new \RuntimeException('Not a built-in backup file (created by another backup adapter?)');
                }
                if ((int) ($header['version'] ?? 0) > self::FORMAT_VERSION) {
                    throw new \RuntimeException('Backup file format version ' . (int) $header['version'] . ' is newer than this module supports');
                }
            }
            $last = $line;
            $count++;
        }

        if ($last === null) {
            throw new \RuntimeException('Backup file is empty');
        }

        try {
            $end = $this->decodeLine($last);
        } catch (\RuntimeException) {
            $end = [];
        }
        if (($end['k'] ?? '') !== 'end' || (int) ($end['records'] ?? -1) !== $count - 1) {
            throw new \RuntimeException('Backup file is incomplete or corrupt');
        }
    }

    /**
     * Replay a verified dump file into the database.
     *
     * @param PDO    $pdo      Active connection (session settings already applied).
     * @param string $filePath Absolute path to the dump file.
     * @return void
     * @throws \RuntimeException If an object cannot be recreated.
     */
    private function applyDump(PDO $pdo, string $filePath): void
    {
        $views = [];
        $code  = [];

        foreach ($this->readLines($filePath) as $line) {
            $record = $this->decodeLine($line);

            switch ($record['k'] ?? '') {
                case 'table':
                    $pdo->exec('DROP TABLE IF EXISTS ' . $this->ident((string) $record['name']));
                    $pdo->exec((string) $record['ddl']);
                    break;
                case 'rows':
                    $this->insertRows($pdo, $record);
                    break;
                case 'view':
                    $views[] = $record;
                    break;
                case 'code':
                    $code[] = $record;
                    break;
                case 'header':
                case 'end':
                    break;
                default:
                    throw new \RuntimeException('Unknown record type in backup file: ' . (string) ($record['k'] ?? ''));
            }
        }

        $this->restoreViews($pdo, $views);

        // Triggers go last so they cannot fire while rows are being inserted.
        foreach ($code as $record) {
            $this->restoreCodeObject($pdo, $record);
        }
    }

    /**
     * Insert one `rows` record with prepared multi-row INSERT statements.
     *
     * @param PDO                  $pdo    Active connection.
     * @param array<string, mixed> $record Decoded `rows` record.
     * @return void
     */
    private function insertRows(PDO $pdo, array $record): void
    {
        /** @var string[] $columns */
        $columns = $record['cols'];
        $perStmt = max(1, intdiv(self::INSERT_PARAM_LIMIT, max(1, count($columns))));
        $rowSql  = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $head    = 'INSERT INTO ' . $this->ident((string) $record['table'])
            . ' (' . implode(', ', array_map([$this, 'ident'], $columns)) . ') VALUES ';

        foreach (array_chunk($record['rows'], $perStmt) as $part) {
            $stmt = $pdo->prepare($head . implode(',', array_fill(0, count($part), $rowSql)));

            $position = 1;
            foreach ($part as $row) {
                foreach ($row as $cell) {
                    $value = $this->decodeCell($cell);
                    $stmt->bindValue($position++, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                }
            }
            $stmt->execute();
        }
    }

    /**
     * Convert a cell from the dump file back into its bindable value.
     *
     * @param mixed $cell Decoded JSON cell.
     * @return string|null Value to bind.
     * @throws \RuntimeException If a base64 cell is malformed.
     */
    private function decodeCell(mixed $cell): ?string
    {
        if ($cell === null) {
            return null;
        }
        if (is_array($cell)) {
            $binary = base64_decode((string) ($cell['b'] ?? ''), true);
            if ($binary === false) {
                throw new \RuntimeException('Malformed binary value in backup file');
            }
            return $binary;
        }

        return (string) $cell;
    }

    /**
     * Recreate views, retrying the ones that depend on views not created yet.
     *
     * @param PDO                              $pdo   Active connection.
     * @param array<int, array<string, mixed>> $views Decoded `view` records in dump order.
     * @return void
     * @throws \RuntimeException If some views still fail when no further progress is possible.
     */
    private function restoreViews(PDO $pdo, array $views): void
    {
        $pending = $views;

        while ($pending !== []) {
            $failed = [];
            $error  = null;

            foreach ($pending as $record) {
                try {
                    $pdo->exec('DROP VIEW IF EXISTS ' . $this->ident((string) $record['name']));
                    $pdo->exec((string) $record['ddl']);
                } catch (\Throwable $e) {
                    $failed[] = $record;
                    $error    = $record['name'] . ': ' . $e->getMessage();
                }
            }

            if (count($failed) === count($pending)) {
                throw new \RuntimeException('Could not recreate view ' . $error);
            }
            $pending = $failed;
        }
    }

    /**
     * Recreate one routine, event or trigger under its original sql_mode.
     *
     * @param PDO                  $pdo    Active connection.
     * @param array<string, mixed> $record Decoded `code` record.
     * @return void
     */
    private function restoreCodeObject(PDO $pdo, array $record): void
    {
        $kind = (string) $record['kind'];
        $name = $this->ident((string) $record['name']);

        $pdo->exec('DROP ' . $kind . ' IF EXISTS ' . $name);

        if (isset($record['sql_mode'])) {
            $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote((string) $record['sql_mode']));
        }
        try {
            $pdo->exec((string) $record['ddl']);
        } finally {
            $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        }
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Get the PDO connection and confirm it is a MySQL/MariaDB one.
     *
     * @return PDO Active connection.
     * @throws \RuntimeException If the driver is not mysql.
     */
    private function connection(): PDO
    {
        $pdo    = $this->database->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            throw new \RuntimeException("PdoDumpBackupAdapter supports the mysql PDO driver only, got '{$driver}'");
        }

        return $pdo;
    }

    /**
     * Create the backup directory (and keep web servers from serving it) when missing.
     *
     * @return void
     * @throws \RuntimeException If the directory cannot be created or written.
     */
    private function prepareBackupDir(): void
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException('Cannot create backup directory: ' . $this->backupDir);
        }
        if (!is_writable($this->backupDir)) {
            throw new \RuntimeException('Backup directory is not writable: ' . $this->backupDir);
        }

        $guards = [
            '.htaccess'  => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            'index.html' => '',
        ];
        foreach ($guards as $name => $content) {
            $path = $this->backupDir . '/' . $name;
            if (!is_file($path) && file_put_contents($path, $content) === false) {
                $this->log('Built-in backup: could not write ' . $path, 'WARNING');
            }
        }
    }

    /**
     * Create the patch_backups table on first use.
     *
     * @param PDO $pdo Active connection.
     * @return void
     * @throws \RuntimeException If the table cannot be created (for example the user lacks CREATE).
     */
    private function ensureBackupTable(PDO $pdo): void
    {
        if ($this->tableReady) {
            return;
        }

        try {
            $pdo->exec(self::BACKUP_TABLE_DDL);
        } catch (\Throwable $e) {
            throw new \RuntimeException('patch_backups bootstrap failed: ' . $e->getMessage(), 0, $e);
        }

        $this->tableReady = true;
    }

    /**
     * Fetch a patch_backups record.
     *
     * @param PDO $pdo      Active connection.
     * @param int $backupId Primary key in patch_backups.
     * @return array<string, mixed>|null The row, or null when no such record exists.
     */
    private function findBackup(PDO $pdo, int $backupId): ?array
    {
        $this->ensureBackupTable($pdo);

        $rows = $this->select($pdo, 'SELECT * FROM `patch_backups` WHERE `id` = :id LIMIT 1', [':id' => $backupId]);

        return $rows[0] ?? null;
    }

    /**
     * Build the absolute path of a backup file from its recorded name.
     *
     * @param string $filename Name stored in patch_backups.
     * @return string Absolute path inside the backup directory.
     * @throws \RuntimeException If the recorded name is not a plain file name.
     */
    private function backupFilePath(string $filename): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1 || str_contains($filename, '..')) {
            throw new \RuntimeException('Invalid backup file name in patch_backups: ' . $filename);
        }

        return $this->backupDir . '/' . $filename;
    }

    /**
     * Run a prepared SELECT and return all rows.
     *
     * @param PDO                  $pdo    Active connection.
     * @param string               $sql    SQL with named placeholders.
     * @param array<string, mixed> $params Placeholder values.
     * @return array<int, array<string, mixed>> Result rows.
     */
    private function select(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Run work with session settings that make the dump and the restore lossless.
     *
     * Sets utf8mb4 transport, a UTC session time zone, NO_AUTO_VALUE_ON_ZERO and
     * disabled foreign key checks, and puts the previous values back afterwards.
     *
     * @param PDO      $pdo  Active connection.
     * @param callable $work Work to run with the settings in place.
     * @return mixed Whatever $work returns.
     */
    private function withSession(PDO $pdo, callable $work): mixed
    {
        $saved = $this->select(
            $pdo,
            'SELECT @@session.sql_mode AS sql_mode, @@session.time_zone AS time_zone,
                    @@session.foreign_key_checks AS fk_checks,
                    @@session.character_set_client AS cs_client,
                    @@session.character_set_results AS cs_results,
                    @@session.collation_connection AS cs_collation'
        )[0];

        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', time_zone = '+00:00', foreign_key_checks = 0");

        try {
            return $work();
        } finally {
            try {
                $results = $saved['cs_results'] === null ? 'NULL' : $pdo->quote((string) $saved['cs_results']);
                $pdo->exec(
                    'SET SESSION sql_mode = ' . $pdo->quote((string) $saved['sql_mode'])
                    . ', time_zone = ' . $pdo->quote((string) $saved['time_zone'])
                    . ', foreign_key_checks = ' . (int) $saved['fk_checks']
                    . ', character_set_client = ' . $pdo->quote((string) $saved['cs_client'])
                    . ', character_set_results = ' . $results
                    . ', collation_connection = ' . $pdo->quote((string) $saved['cs_collation'])
                );
            } catch (\Throwable $e) {
                $this->log('Built-in backup: could not restore the session settings: ' . $e->getMessage(), 'WARNING');
            }
        }
    }

    /**
     * Connection attribute that switches between buffered and streamed result sets.
     *
     * The class constant exists from PHP 8.4; the PDO constant is its older spelling.
     *
     * @return int PDO attribute identifier.
     */
    private function bufferedQueryAttribute(): int
    {
        return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            ? (int) constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
            : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    /**
     * Read a gzip file line by line.
     *
     * @param string $filePath Absolute path to a .gz file.
     * @return \Generator<int, string> Lines without the trailing newline.
     * @throws \RuntimeException If the file cannot be opened.
     */
    private function readLines(string $filePath): \Generator
    {
        $gz = gzopen($filePath, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Cannot open backup file: ' . $filePath);
        }

        try {
            $buffer = '';
            while (!gzeof($gz)) {
                $part = gzgets($gz, 1048576);
                if ($part === false) {
                    break;
                }
                $buffer .= $part;
                if (str_ends_with($buffer, "\n")) {
                    yield rtrim($buffer, "\n");
                    $buffer = '';
                }
            }
            if ($buffer !== '') {
                yield $buffer;
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * Decode one JSON line of a dump file.
     *
     * @param string $line Raw line.
     * @return array<string, mixed> Decoded record.
     * @throws \RuntimeException If the line is not a JSON object.
     */
    private function decodeLine(string $line): array
    {
        try {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Backup file is corrupt: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($record)) {
            throw new \RuntimeException('Backup file is corrupt: record is not an object');
        }

        return $record;
    }

    /**
     * Quote an identifier for use in SQL.
     *
     * @param string $name Table, column or object name.
     * @return string Backtick-quoted identifier.
     */
    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Delete a file and log when that fails.
     *
     * @param string $filePath Absolute path.
     * @return void
     */
    private function removeFile(string $filePath): void
    {
        if (is_file($filePath) && !unlink($filePath)) {
            $this->log('Built-in backup: could not remove partial file ' . $filePath, 'WARNING');
        }
    }

    /**
     * Log a message if a logger is available.
     *
     * @param string $message Log message.
     * @param string $level   Log level.
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logger !== null) {
            $this->logger->log($message, $level);
        }
    }
}
