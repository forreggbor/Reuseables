<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Mysqldump-based database backup adapter for PatchModule.
 */

declare(strict_types=1);

namespace PatchModule\Adapters\Backup;

use PatchModule\Contracts\BackupAdapterHealthInterface;
use PatchModule\Contracts\BackupAdapterInterface;
use PDO;

/**
 * Implements BackupAdapterInterface using mysqldump/mariadb-dump and mysql/mariadb CLI tools.
 *
 * Creates gzip-compressed SQL dumps before patch installation and restores
 * them on rollback. Records metadata in the patch_backups table using
 * individual queries (no transactions, to avoid MySQL DDL lock issues).
 *
 * Supports both MariaDB (mariadb-dump, mariadb) and MySQL (mysqldump, mysql)
 * binaries, auto-detecting whichever is available. Uses pipefail and stderr
 * redirection to avoid warning pollution in SQL dumps and to correctly
 * propagate exit codes through pipes.
 *
 * The database credentials never appear on a command line (and so never in the process list): they are
 * handed to the dump and client binaries through a temporary option file (`--defaults-extra-file`,
 * mode 0600) that is wiped and removed as soon as the command has finished, on success and on failure.
 */
class MysqldumpBackupAdapter implements BackupAdapterInterface, BackupAdapterHealthInterface
{
    /** @var PDO Database connection. */
    private PDO $pdo;

    /** @var string Directory where backup files are stored. */
    private string $backupDir;

    /** @var string Database host. */
    private string $dbHost;

    /** @var string Database name. */
    private string $dbName;

    /** @var string Database user. */
    private string $dbUser;

    /** @var string Database password. */
    private string $dbPass;

    /** @var int Database port. */
    private int $dbPort;

    /** @var string Resolved dump binary (mariadb-dump or mysqldump). */
    private string $dumpBinary;

    /** @var string Resolved client binary (mariadb or mysql). */
    private string $clientBinary;

    /**
     * @param PDO    $pdo       Active PDO connection for metadata operations.
     * @param string $backupDir Writable directory for backup files.
     * @param string $dbHost    Database host.
     * @param string $dbName    Database name to dump.
     * @param string $dbUser    Database user.
     * @param string $dbPass    Database password.
     * @param int    $dbPort    Database port (default 3306).
     *
     * @throws \RuntimeException If neither dump nor client binary is found on the system.
     */
    public function __construct(
        PDO    $pdo,
        string $backupDir,
        string $dbHost,
        string $dbName,
        string $dbUser,
        string $dbPass,
        int    $dbPort = 3306
    ) {
        $this->pdo       = $pdo;
        $this->backupDir = rtrim($backupDir, '/');
        $this->dbHost    = $dbHost;
        $this->dbName    = $dbName;
        $this->dbUser    = $dbUser;
        $this->dbPass    = $dbPass;
        $this->dbPort    = $dbPort;

        $dumpBinary = self::detectBinary(['mariadb-dump', 'mysqldump']);
        if ($dumpBinary === null) {
            throw new \RuntimeException('No dump binary found: tried mariadb-dump, mysqldump');
        }
        $this->dumpBinary = $dumpBinary;

        $clientBinary = self::detectBinary(['mariadb', 'mysql']);
        if ($clientBinary === null) {
            throw new \RuntimeException('No client binary found: tried mariadb, mysql');
        }
        $this->clientBinary = $clientBinary;
    }

    /**
     * {@inheritdoc}
     *
     * Runs the dump binary and gzips the result into the backup directory.
     * Uses pipefail to capture dump exit code and redirects stderr to a temp
     * file to prevent deprecation warnings from polluting the SQL stream.
     * Records the backup in patch_backups using individual INSERT query.
     */
    public function createBackup(string $note, ?int $userId = null): array
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0750, true)) {
            return ['success' => false, 'backup_id' => null, 'error' => 'Cannot create backup directory: ' . $this->backupDir];
        }

        $timestamp  = date('Y-m-d_His');
        $filename   = 'patch_backup_' . $timestamp . '.sql.gz';
        $filePath   = $this->backupDir . '/' . $filename;
        try {
            $optionFile = $this->createOptionFile();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'backup_id' => null, 'error' => $e->getMessage()];
        }

        $stderrFile = tempnam(sys_get_temp_dir(), 'mysqldump_stderr_');

        // All arguments are sanitised with escapeshellarg(). The credentials come from the option file,
        // which must be the first option. stderr is redirected to a temp file to keep it out of the SQL
        // stream. pipefail ensures the dump binary's exit code is returned, not gzip's.
        $command = sprintf(
            'bash -c "set -o pipefail; %s --defaults-extra-file=%s --single-transaction --routines --triggers --quick %s 2>%s | gzip > %s"',
            $this->dumpBinary,
            escapeshellarg($optionFile),
            escapeshellarg($this->dbName),
            escapeshellarg($stderrFile),
            escapeshellarg($filePath)
        );

        $output     = [];
        $returnCode = 0;
        try {
            exec($command, $output, $returnCode);
        } finally {
            self::removeOptionFile($optionFile);
        }

        $stderrContent = '';
        if (file_exists($stderrFile)) {
            $stderrContent = trim((string)file_get_contents($stderrFile));
            @unlink($stderrFile);
        }

        if ($returnCode !== 0) {
            @unlink($filePath);
            $errorDetail = $stderrContent !== '' ? $stderrContent : implode(' ', $output);
            return [
                'success'   => false,
                'backup_id' => null,
                'error'     => $this->dumpBinary . ' failed (exit ' . $returnCode . '): ' . $errorDetail,
            ];
        }

        if (!file_exists($filePath) || filesize($filePath) === 0) {
            return ['success' => false, 'backup_id' => null, 'error' => 'Backup file was not created or is empty.'];
        }

        $fileSize = (int)filesize($filePath);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO patch_backups (filename, file_size, note, created_at, created_by)
                 VALUES (:filename, :file_size, :note, NOW(), :created_by)'
            );
            $stmt->execute([
                ':filename'   => $filename,
                ':file_size'  => $fileSize,
                ':note'       => $note,
                ':created_by' => $userId,
            ]);
            $backupId = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            // DB record failed — remove file to avoid orphan
            @unlink($filePath);
            return ['success' => false, 'backup_id' => null, 'error' => 'Failed to record backup: ' . $e->getMessage()];
        }

        return ['success' => true, 'backup_id' => $backupId, 'error' => null];
    }

    /**
     * {@inheritdoc}
     *
     * Gunzips the backup file and pipes it into the client CLI to restore.
     * Both gunzip and client stderr are redirected to a temp file and reported
     * on failure. pipefail ensures a gunzip failure is not masked by the client.
     */
    public function restoreDatabase(int $backupId): array
    {
        $record = $this->findBackup($backupId);
        if ($record === null) {
            return ['success' => false, 'error' => 'Backup record not found: ' . $backupId];
        }

        $filePath   = $this->backupDir . '/' . $record['filename'];
        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'Backup file not found: ' . $filePath];
        }

        try {
            $optionFile = $this->createOptionFile();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $stderrFile = tempnam(sys_get_temp_dir(), 'mysqlrestore_stderr_');

        // All arguments are sanitised with escapeshellarg(). The credentials come from the option file,
        // which must be the first option. Both gunzip and client stderr go to the same temp file (append
        // mode for the client). pipefail ensures a gunzip failure propagates as the command exit code.
        $command = sprintf(
            'bash -c "set -o pipefail; gunzip -c %s 2>%s | %s --defaults-extra-file=%s %s 2>>%s"',
            escapeshellarg($filePath),
            escapeshellarg($stderrFile),
            $this->clientBinary,
            escapeshellarg($optionFile),
            escapeshellarg($this->dbName),
            escapeshellarg($stderrFile)
        );

        $output     = [];
        $returnCode = 0;
        try {
            exec($command, $output, $returnCode);
        } finally {
            self::removeOptionFile($optionFile);
        }

        $stderrContent = '';
        if (file_exists($stderrFile)) {
            $stderrContent = trim((string)file_get_contents($stderrFile));
            @unlink($stderrFile);
        }

        if ($returnCode !== 0) {
            $errorDetail = $stderrContent !== '' ? $stderrContent : implode(' ', $output);
            return [
                'success' => false,
                'error'   => 'Restore failed (exit ' . $returnCode . '): ' . $errorDetail,
            ];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * {@inheritdoc}
     *
     * Deletes the backup file and removes the DB record using individual queries.
     */
    public function deleteBackup(int $backupId): array
    {
        $record = $this->findBackup($backupId);
        if ($record === null) {
            return ['success' => false, 'error' => 'Backup record not found: ' . $backupId];
        }

        $filePath = $this->backupDir . '/' . $record['filename'];
        if (file_exists($filePath) && !unlink($filePath)) {
            return ['success' => false, 'error' => 'Could not delete backup file: ' . $filePath];
        }

        try {
            $stmt = $this->pdo->prepare('DELETE FROM patch_backups WHERE id = :id');
            $stmt->execute([':id' => $backupId]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Could not delete backup record: ' . $e->getMessage()];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * {@inheritdoc}
     */
    public function getFreeDiskSpace(): int
    {
        $free = disk_free_space($this->backupDir);
        return $free !== false ? (int)$free : 0;
    }

    /**
     * Check whether a supported dump binary is available via exec().
     *
     * Tries mariadb-dump first, then mysqldump.
     *
     * @return bool True if exec() and at least one supported dump binary are available.
     */
    public static function isAvailable(): bool
    {
        return self::execEnabled() && self::detectBinary(['mariadb-dump', 'mysqldump']) !== null;
    }

    /**
     * {@inheritdoc}
     *
     * Checks exec(), the backup directory and the patch_backups table; the binaries were resolved in the
     * constructor. Cheap enough to run on every admin page render (no process is started).
     */
    public function checkOperational(): array
    {
        if (!self::execEnabled()) {
            return ['ok' => false, 'reason' => 'exec() is not available (missing or listed in disable_functions).'];
        }

        $dirProblem = BackupDirectoryCheck::problem($this->backupDir);
        if ($dirProblem !== null) {
            return ['ok' => false, 'reason' => $dirProblem];
        }

        try {
            if ($this->pdo->query('SELECT 1 FROM patch_backups LIMIT 1') === false) {
                return ['ok' => false, 'reason' => 'The patch_backups table is not readable (run schema/patch_backups.sql).'];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'The patch_backups table is not readable (run schema/patch_backups.sql): ' . $e->getMessage()];
        }

        return ['ok' => true, 'reason' => null];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Write the credentials into a temporary MySQL/MariaDB option file (mode 0600).
     *
     * Values are double-quoted with `\` and `"` escaped, so characters such as `#` or quotes in the
     * password survive. A line break or NUL byte in any value is refused: it would let the value inject
     * further option-file directives.
     *
     * @return string Path of the option file; the caller removes it with removeOptionFile().
     *
     * @throws \RuntimeException If a credential contains a line break or NUL byte, or the file cannot be written.
     */
    private function createOptionFile(): string
    {
        foreach (['host' => $this->dbHost, 'user' => $this->dbUser, 'password' => $this->dbPass] as $name => $value) {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new \RuntimeException('Database credential "' . $name . '" must not contain a line break or NUL byte.');
            }
        }

        $tempDir = sys_get_temp_dir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new \RuntimeException('Cannot create the temporary option file: the temp directory is not writable.');
        }
        $path = tempnam($tempDir, 'patch_mysql_');
        if ($path === false) {
            throw new \RuntimeException('Cannot create the temporary option file.');
        }

        $content = "[client]\n"
            . 'host=' . self::quoteOptionValue($this->dbHost) . "\n"
            . 'port=' . $this->dbPort . "\n"
            . 'user=' . self::quoteOptionValue($this->dbUser) . "\n"
            . 'password=' . self::quoteOptionValue($this->dbPass) . "\n";

        // tempnam() already creates the file with mode 0600; set it again before the secret is written.
        if (!chmod($path, 0600) || file_put_contents($path, $content) === false) {
            self::removeOptionFile($path);
            throw new \RuntimeException('Cannot write the temporary option file.');
        }

        return $path;
    }

    /**
     * Quote a value for an option file: double quotes around it, backslash and double quote escaped.
     *
     * @param string $value Raw value (already checked for line breaks).
     *
     * @return string Quoted value.
     */
    private static function quoteOptionValue(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    /**
     * Wipe and delete a temporary option file.
     *
     * @param string $optionFile Path returned by createOptionFile().
     *
     * @return void
     */
    private static function removeOptionFile(string $optionFile): void
    {
        if (is_file($optionFile)) {
            file_put_contents($optionFile, str_repeat("\0", 256));
            unlink($optionFile);
        }
    }

    /**
     * Whether PHP's exec() exists and is not disabled.
     *
     * @return bool
     */
    private static function execEnabled(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled !== false && $disabled !== '') {
            return !in_array('exec', array_map('trim', explode(',', $disabled)), true);
        }

        return true;
    }

    /**
     * Return the first candidate binary that is executable on this system.
     *
     * Runs `{candidate} --version 2>/dev/null` for each entry and returns
     * the first one that exits with code 0.
     *
     * @param string[] $candidates Binary names to try in order.
     *
     * @return string|null The first working binary name, or null if none found.
     */
    private static function detectBinary(array $candidates): ?string
    {
        foreach ($candidates as $binary) {
            $returnCode = 0;
            exec($binary . ' --version 2>/dev/null', $ignored, $returnCode);
            if ($returnCode === 0) {
                return $binary;
            }
        }
        return null;
    }

    /**
     * Fetch a backup record by ID.
     *
     * @param int $backupId Primary key in patch_backups.
     *
     * @return array<string, mixed>|null Backup row or null if not found.
     */
    private function findBackup(int $backupId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM patch_backups WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $backupId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
