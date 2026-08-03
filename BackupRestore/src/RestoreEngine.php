<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * RestoreEngine — atomic (temp-database RENAME swap) and in-place (table
 * rename within the same database) restore, with automatic rollback, plus
 * live-root file restore with a pre-restore snapshot and rollback.
 *
 * This is the most safety-critical part of the module: every destructive
 * step here is guarded so that a failure never leaves the database or the
 * project files in a half-restored, unrecoverable state — see the extensive
 * inline reasoning on {@see restoreDatabaseAtomic()}, {@see restoreDatabaseInPlace()},
 * {@see rollbackInPlaceRestore()}, and {@see rollbackFileRestore()}.
 */

namespace BackupRestore;

use BackupRestore\Contracts\TranslatorInterface;
use BackupRestore\Exec\ExecHelper;

/**
 * @package BackupRestore
 */
final class RestoreEngine
{
    /** Grace window before an orphaned _old_* recovery database is auto-reclaimed. */
    private const int ORPHANED_RECOVERY_DB_GRACE_HOURS = 168; // 7 days

    /**
     * Minimum age before an orphaned _restore_* database is swept — guards against
     * dropping a database that still belongs to a genuinely concurrent restore
     * attempt (there is no app-level lock around the restore chain).
     */
    private const int ORPHANED_RESTORE_DB_MIN_AGE_SECONDS = 1800; // 30 minutes

    /**
     * Hard headroom required in addition to the estimated need, on top of any
     * disk-space check performed before a destructive restore step.
     */
    private const int RESTORE_DISK_HEADROOM_BYTES = 100 * 1024 * 1024; // 100MB

    private readonly Translator $t;

    /** @var string|null Progress file path for the currently running restore */
    private ?string $progressFile = null;

    /** @var array<int,array{id:string,status:string}> Current progress steps state */
    private array $progressSteps = [];

    /**
     * @param BackupEngine $backupEngine Used to resolve backup records/paths and archive integrity
     * @param string $rootPath Absolute project root (file-restore base)
     * @param string $tempPath Absolute scratch directory
     * @param callable(string,string):void $logger
     * @param TranslatorInterface|null $translator
     * @param string[] $criticalTables Table names whose post-restore emptiness signals
     *        a botched full restore (e.g. ['users','system_settings'] — a login table
     *        and a settings table). Empty (default) skips this sanity check entirely;
     *        a host wires its own reachability-critical tables here.
     */
    public function __construct(
        private readonly BackupEngine $backupEngine,
        private readonly string $rootPath,
        private readonly string $tempPath,
        private $logger,
        ?TranslatorInterface $translator,
        private readonly array $criticalTables = [],
    ) {
        $this->t = new Translator($translator);
    }

    // =========================================================================
    // Progress tracking
    // =========================================================================

    /**
     * Compute the sanitized progress-file path for a given token, so a host's
     * poll route can locate the JSON this engine writes to during a restore.
     * The token is sanitized internally (never trust a client-supplied token
     * as a bare filesystem path segment).
     *
     * @param string $token
     * @return string Absolute path to the progress JSON file
     */
    public function progressFilePath(string $token): string
    {
        $safeToken = preg_replace('/[^a-zA-Z0-9_]/', '', $token) ?? '';
        return rtrim($this->tempPath, '/') . '/restore_progress_' . $safeToken . '.json';
    }

    /**
     * Set the progress file path for restore progress tracking.
     *
     * @param string $path Absolute path to the progress JSON file
     * @return void
     */
    public function setProgressFile(string $path): void
    {
        $this->progressFile = $path;
    }

    /**
     * Initialize restore progress with a list of step IDs. All steps start as
     * "pending". Writes the initial state to the progress file.
     *
     * @param array<int,string> $stepIds
     * @return void
     */
    private function initProgress(array $stepIds): void
    {
        $this->progressSteps = array_map(fn (string $id) => ['id' => $id, 'status' => 'pending'], $stepIds);
        $this->writeProgress();
    }

    /**
     * Mark a restore step as active (in progress). Any previously active step
     * is automatically marked as completed.
     *
     * @param string $stepId
     * @return void
     */
    private function stepProgress(string $stepId): void
    {
        if ($this->progressFile === null) {
            return;
        }

        foreach ($this->progressSteps as &$step) {
            if ($step['id'] === $stepId) {
                $step['status'] = 'active';
                break;
            }
            if ($step['status'] === 'active' || $step['status'] === 'pending') {
                $step['status'] = 'completed';
            }
        }
        unset($step);
        $this->writeProgress();
    }

    /**
     * Mark all remaining steps as completed.
     *
     * @return void
     */
    private function completeProgress(): void
    {
        if ($this->progressFile === null) {
            return;
        }

        foreach ($this->progressSteps as &$step) {
            if ($step['status'] === 'active' || $step['status'] === 'pending') {
                $step['status'] = 'completed';
            }
        }
        unset($step);
        $this->writeProgress();
    }

    /**
     * Mark a step as failed.
     *
     * @param string $stepId
     * @return void
     */
    private function failProgress(string $stepId): void
    {
        if ($this->progressFile === null) {
            return;
        }

        foreach ($this->progressSteps as &$step) {
            if ($step['id'] === $stepId) {
                $step['status'] = 'failed';
            } elseif ($step['status'] === 'active') {
                $step['status'] = 'failed';
            }
        }
        unset($step);
        $this->writeProgress();
    }

    /**
     * Write progress state to the progress file atomically (temp file + rename,
     * so the polling endpoint never reads a partial write).
     *
     * @return void
     */
    private function writeProgress(): void
    {
        if ($this->progressFile === null) {
            return;
        }

        $dir = dirname($this->progressFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $data = json_encode(['steps' => $this->progressSteps], JSON_UNESCAPED_UNICODE);
        $tmp = $this->progressFile . '.tmp';
        file_put_contents($tmp, $data);
        rename($tmp, $this->progressFile);
    }

    // =========================================================================
    // Disk space
    // =========================================================================

    /**
     * Verify that the PHP/storage filesystem (holding the temp path) has enough
     * free space for an upcoming restore step, failing BEFORE any destructive
     * operation is attempted. Only covers the PHP-disk side — see
     * {@see logDatabaseSizeEstimate()} for why the MySQL datadir side is
     * deliberately not blocked on here.
     *
     * @param int $requiredBytes Estimated bytes the operation needs, excluding headroom
     * @param string $context Human-readable label for logging (e.g. "database restore")
     * @return array{success: bool, error: string|null}
     */
    private function ensureDiskSpace(int $requiredBytes, string $context): array
    {
        $needed = $requiredBytes + self::RESTORE_DISK_HEADROOM_BYTES;
        $free = (int) disk_free_space($this->tempPath);

        if ($free < $needed) {
            $message = sprintf(
                '%s: %s free, but ~%s needed (including %s headroom)',
                $context,
                FileSize::format($free),
                FileSize::format($needed),
                FileSize::format(self::RESTORE_DISK_HEADROOM_BYTES)
            );
            $this->log("[Restore/DiskSpace] Insufficient space for {$message}", 'ERROR');
            return ['success' => false, 'error' => $this->t->translate('TEXT_BACKUP_INSUFFICIENT_DISK_SPACE', ['details' => $message])];
        }

        $this->log("[Restore/DiskSpace] OK for {$context}: " . FileSize::format($free) . ' free, ~' . FileSize::format($needed) . ' needed', 'DEBUG');
        return ['success' => true, 'error' => null];
    }

    /**
     * Log (non-blocking) an estimate of the live database's on-disk size, as a
     * signal for the MySQL datadir headroom an in-place restore transiently
     * needs (roughly 2x the data size: the live tables plus the `_bak_*` copy
     * plus, during rollback, the snapshot import).
     *
     * Deliberately NOT a hard, blocking check: the datadir (typically mode 700,
     * owned by the `mysql` user) is usually unreadable to the PHP/web user via
     * disk_free_space(), and on the common single-server, same-filesystem
     * deployment {@see ensureDiskSpace()} already covers it. On deployments
     * where the datadir is a SEPARATE filesystem, this risk is knowingly
     * accepted and left to the host's own operational documentation rather
     * than a check that would silently no-op or give false confidence.
     *
     * @param array $creds Database credentials
     * @return void
     */
    private function logDatabaseSizeEstimate(array $creds): void
    {
        $dbName = $creds['database'];
        $result = ExecHelper::mysqlQuery(
            "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = '{$dbName}'",
            $creds
        );

        if (!$result['success'] || empty($result['rows'][0][0])) {
            $this->log("[Restore/DiskSpace] Could not estimate database size for datadir headroom logging", 'DEBUG');
            return;
        }

        $sizeBytes = (int) $result['rows'][0][0];
        $this->log(
            '[Restore/DiskSpace] Live database data+index size ~' . FileSize::format($sizeBytes) .
            ' — in-place restore transiently needs roughly 2x this on the MySQL datadir (not verified by this check)',
            'INFO'
        );
    }

    // =========================================================================
    // DEFINER stripping
    // =========================================================================

    /**
     * Strip DEFINER clauses from a SQL file to allow import by non-root users.
     *
     * mysqldump embeds DEFINER=`user`@`host` in conditional comments for
     * triggers, routines, events, and views. Non-root users without SUPER
     * privilege cannot import these. This strips all DEFINER clauses, causing
     * MySQL to default to CURRENT_USER as the definer.
     *
     * @param string $filePath Path to the SQL file to modify in place
     * @return bool True if stripping succeeded, false on failure
     */
    private function stripDefinerFromSqlFile(string $filePath): bool
    {
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            $this->log("[Restore/Definer] File not found or empty: {$filePath}", 'WARNING');
            return false;
        }

        $result = ExecHelper::stripDefinerFromFile($filePath);

        if ($result) {
            $this->log("[Restore/Definer] Stripped DEFINER clauses from: {$filePath}", 'DEBUG');
        }

        return $result;
    }

    // =========================================================================
    // Rollback snapshot (in-place strategy safety net)
    // =========================================================================

    /**
     * Create a rollback snapshot of the current database via mysqldump.
     *
     * Used by in-place restore to guarantee complete rollback including
     * FKs, triggers, routines, events, and views.
     *
     * @param array $creds Database credentials
     * @return string|false Snapshot file path on success, false on failure
     */
    private function createRollbackSnapshot(array $creds): string|false
    {
        $snapshotPath = $this->tempPath . '/rollback_snapshot_' . date('Ymd_His') . '.sql';
        $this->log("[Restore/Snapshot] Creating rollback snapshot: {$snapshotPath}", 'DEBUG');

        $dumpResult = ExecHelper::mysqldump($creds, $snapshotPath);

        if (!$dumpResult['success']) {
            $this->log("[Restore/Snapshot] mysqldump failed: " . $dumpResult['error'], 'ERROR');
            if (file_exists($snapshotPath)) {
                unlink($snapshotPath);
            }
            return false;
        }

        // Validate the snapshot is non-empty AND actually contains at least one
        // CREATE TABLE statement — filesize() alone would accept a dump that
        // completed with a zero exit code but produced only comments/headers
        // (e.g. mysqldump succeeding against an empty or inaccessible schema),
        // which would then be trusted as a full rollback snapshot later.
        if (!file_exists($snapshotPath) || filesize($snapshotPath) === 0) {
            $this->log("[Restore/Snapshot] Snapshot file is empty or missing", 'ERROR');
            if (file_exists($snapshotPath)) {
                unlink($snapshotPath);
            }
            return false;
        }

        $handle = fopen($snapshotPath, 'r');
        $hasCreateTable = false;
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                if (stripos($line, 'CREATE TABLE') !== false) {
                    $hasCreateTable = true;
                    break;
                }
            }
            fclose($handle);
        }
        if (!$hasCreateTable) {
            $this->log("[Restore/Snapshot] Snapshot file contains no CREATE TABLE statement — refusing to trust it as a rollback snapshot", 'ERROR');
            unlink($snapshotPath);
            return false;
        }

        // Strip DEFINER clauses so non-root users can import the snapshot
        ExecHelper::stripDefinerFromFile($snapshotPath);

        $snapshotSize = filesize($snapshotPath);
        $this->log("[Restore/Snapshot] Snapshot created: " . number_format($snapshotSize) . " bytes", 'DEBUG');
        return $snapshotPath;
    }

    // =========================================================================
    // Trigger management (cross-database RENAME cannot carry triggers)
    // =========================================================================

    /**
     * Get all trigger definitions from a database.
     *
     * Uses SHOW TRIGGERS and SHOW CREATE TRIGGER to capture exact DDL for
     * each trigger, enabling faithful recreation after cross-database rename.
     *
     * Callers performing a partial restore MUST pass $scopeTables (the
     * managed/_bak_ table names actually being renamed/swapped this restore).
     * Without it, every trigger in the schema is captured and later dropped —
     * including triggers on tables the restore never touches, which are then
     * never recreated because the golden dump never contained them.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name
     * @param string[]|null $scopeTables When provided, only triggers whose
     *        EVENT_OBJECT_TABLE is in this list are returned. Null preserves
     *        the schema-wide behavior (a full restore, or an isolated temp DB
     *        that only ever contains the tables being imported).
     * @return array<int,array{name:string,ddl:string}>
     */
    private function getDatabaseTriggers(array $creds, string $dbName, ?array $scopeTables = null): array
    {
        $sql = "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$dbName}'";
        if ($scopeTables !== null) {
            if (empty($scopeTables)) {
                return [];
            }
            $inList = Identifier::quoteStringList($scopeTables);
            $sql .= " AND EVENT_OBJECT_TABLE IN ({$inList})";
        }

        $result = ExecHelper::mysqlQuery($sql, $creds);

        if (!$result['success'] || empty($result['rows'])) {
            return [];
        }

        $triggerNames = array_map(fn ($r) => $r[0], $result['rows']);
        $triggers = [];

        foreach ($triggerNames as $triggerName) {
            $ddlResult = ExecHelper::mysqlQuery('SHOW CREATE TRIGGER ' . Identifier::quote($triggerName), $creds, $dbName);

            if ($ddlResult['success'] && !empty($ddlResult['rows'])) {
                // SHOW CREATE TRIGGER returns: Trigger, sql_mode, SQL Original Statement, ...
                $ddl = $ddlResult['rows'][0][2] ?? '';
                if (!empty($ddl)) {
                    $ddl = preg_replace('/\s*DEFINER=`[^`]*`@`[^`]*`\s*/', ' ', $ddl);
                    $triggers[] = ['name' => $triggerName, 'ddl' => $ddl];
                }
            }
        }

        $this->log("[Restore/Triggers] Found " . count($triggers) . " triggers in database {$dbName}", 'DEBUG');
        return $triggers;
    }

    /**
     * Drop all specified triggers from a database.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name
     * @param array<int,array{name:string,ddl:string}> $triggers
     * @return void
     */
    private function dropDatabaseTriggers(array $creds, string $dbName, array $triggers): void
    {
        if (empty($triggers)) {
            return;
        }

        $dropStatements = [];
        foreach ($triggers as $trigger) {
            $dropStatements[] = 'DROP TRIGGER IF EXISTS ' . Identifier::quote($trigger['name']);
        }
        $dropSQL = implode('; ', $dropStatements) . ';';

        $result = ExecHelper::mysqlExec($dropSQL, $creds, $dbName);

        if (!$result['success']) {
            $this->log("[Restore/Triggers] Failed to drop triggers: " . $result['error'], 'WARNING');
        } else {
            $this->log("[Restore/Triggers] Dropped " . count($triggers) . " triggers from {$dbName}", 'DEBUG');
        }
    }

    /**
     * Recreate triggers in a database from saved DDL statements.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name
     * @param array<int,array{name:string,ddl:string}> $triggers
     * @return int Number of triggers that failed to recreate
     */
    private function recreateDatabaseTriggers(array $creds, string $dbName, array $triggers): int
    {
        if (empty($triggers)) {
            return 0;
        }

        $errors = 0;
        foreach ($triggers as $trigger) {
            // Drop first (avoid duplicates) — a simple statement, safe via mysqlExec().
            ExecHelper::mysqlExec('DROP TRIGGER IF EXISTS ' . Identifier::quote($trigger['name']), $creds, $dbName);

            // Defense-in-depth: the trigger DDL was pulled out of a restored
            // (untrusted) database via SHOW CREATE TRIGGER. A prefix check
            // cannot stop a sufficiently crafted payload on its own — full
            // safety requires signed/HMAC-verified backups (future hardening
            // scope) — but it rejects anything that isn't shaped like the two
            // forms SHOW CREATE TRIGGER actually returns, before it's ever
            // written to disk and imported.
            if (!$this->isSafeTriggerDdl($trigger['ddl'])) {
                $errors++;
                $this->log("[Restore/Triggers] Refused to recreate trigger {$trigger['name']}: DDL does not match the expected CREATE TRIGGER shape", 'WARNING');
                continue;
            }

            // The CREATE itself has a compound BEGIN...END body containing internal
            // semicolons. mysqlExec() has no notion of DELIMITER or BEGIN/END nesting,
            // so it would fragment the trigger body at its first internal semicolon.
            // Route it through mysqlImport() instead (DELIMITER-wrapped, via a temp
            // file) — the way a mysqldump-produced trigger definition is normally imported.
            $tempFile = tempnam($this->tempPath, 'trigger_');
            file_put_contents($tempFile, "DELIMITER $$\n{$trigger['ddl']}$$\nDELIMITER ;\n");
            $result = ExecHelper::mysqlImport($creds, $dbName, $tempFile);
            @unlink($tempFile);

            if (!$result['success']) {
                $errors++;
                $this->log("[Restore/Triggers] Failed to recreate trigger {$trigger['name']}: " . $result['error'], 'WARNING');
            }
        }

        if ($errors === 0) {
            $this->log("[Restore/Triggers] Recreated " . count($triggers) . " triggers in {$dbName}", 'DEBUG');
        } else {
            $this->log("[Restore/Triggers] {$errors}/" . count($triggers) . " triggers failed to recreate in {$dbName}", 'WARNING');
        }

        return $errors;
    }

    /**
     * Defence-in-depth guard for trigger DDL pulled out of a restored
     * (untrusted) database. Accepts only statements that begin with
     * CREATE TRIGGER or CREATE DEFINER=... TRIGGER — the two shapes
     * SHOW CREATE TRIGGER returns. Mirrors standalone/restore.php's
     * restore_is_safe_trigger_ddl() (kept in sync manually — the standalone
     * script cannot depend on the framework autoloader).
     *
     * @param string $ddl
     * @return bool
     */
    private function isSafeTriggerDdl(string $ddl): bool
    {
        $trimmed = ltrim($ddl);
        return (bool) preg_match('/^CREATE\s+(DEFINER\s*=\s*`[^`]*`@`[^`]*`\s+)?TRIGGER\s+/i', $trimmed);
    }

    // =========================================================================
    // Foreign-key capture/rebuild helpers (shared by restoreDatabaseAtomic()
    // and restoreDatabaseInPlace() — partial restores must protect FKs that
    // cross the managed/non-managed table boundary; both strategies capture,
    // drop, and rebuild them the same way, only the target database name and
    // failure handling differ per call site).
    // =========================================================================

    /**
     * Map raw information_schema.KEY_COLUMN_USAGE/REFERENTIAL_CONSTRAINTS rows
     * (TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME,
     * REFERENCED_COLUMN_NAME, DELETE_RULE, UPDATE_RULE) into the associative
     * FK-descriptor shape used by the rebuild helpers below.
     *
     * @param array<int,array> $rows
     * @return array<int,array{table:string,constraint:string,column:string,ref_table:string,ref_column:string,delete_rule:string,update_rule:string}>
     */
    private static function mapForeignKeyRows(array $rows): array
    {
        $fks = [];
        foreach ($rows as $row) {
            $fks[] = [
                'table' => $row[0],
                'constraint' => $row[1],
                'column' => $row[2],
                'ref_table' => $row[3],
                'ref_column' => $row[4],
                'delete_rule' => $row[5],
                'update_rule' => $row[6],
            ];
        }
        return $fks;
    }

    /**
     * Query FKs defined on tables OUTSIDE $tables that reference a table IN
     * $tables ("external inbound" FKs) — captured before a partial restore's
     * table swap/rename so they can be dropped (MariaDB auto-updates them
     * when the referenced table is renamed away) and rebuilt afterward
     * pointing at the same table under its permanent name again.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name to query
     * @param string[] $tables Managed table names
     * @return array<int,array{table:string,constraint:string,column:string,ref_table:string,ref_column:string,delete_rule:string,update_rule:string}>
     */
    private function queryExternalInboundForeignKeys(array $creds, string $dbName, array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        $inList = Identifier::quoteStringList($tables);
        $result = ExecHelper::mysqlQuery(
            "SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM   information_schema.KEY_COLUMN_USAGE kcu
             JOIN   information_schema.REFERENTIAL_CONSTRAINTS rc
                        ON  rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                        AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             WHERE  kcu.TABLE_SCHEMA          = '{$dbName}'
               AND  kcu.REFERENCED_TABLE_NAME IN ({$inList})
               AND  kcu.TABLE_NAME            NOT IN ({$inList})",
            $creds
        );

        if (!$result['success'] || empty($result['rows'])) {
            return [];
        }

        return self::mapForeignKeyRows($result['rows']);
    }

    /**
     * Query FKs defined ON a table IN $tables that reference a table OUTSIDE
     * $tables ("outbound" FKs) — the golden dump's own CREATE TABLE for a
     * managed table still defines these, so importing it into an isolated
     * temp database recreates them pointing at a same-named table that does
     * not exist there; RENAME TABLE does not fix this (it only updates FKs
     * that point AT a renamed table, not FKs defined ON it), so the caller
     * must drop+recreate them against the live table after the swap.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name to query
     * @param string[] $tables Managed table names
     * @return array<int,array{table:string,constraint:string,column:string,ref_table:string,ref_column:string,delete_rule:string,update_rule:string}>
     */
    private function queryOutboundForeignKeys(array $creds, string $dbName, array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        $inList = Identifier::quoteStringList($tables);
        $result = ExecHelper::mysqlQuery(
            "SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM   information_schema.KEY_COLUMN_USAGE kcu
             JOIN   information_schema.REFERENTIAL_CONSTRAINTS rc
                        ON  rc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                        AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
             WHERE  kcu.TABLE_SCHEMA          = '{$dbName}'
               AND  kcu.TABLE_NAME            IN ({$inList})
               AND  kcu.REFERENCED_TABLE_NAME  NOT IN ({$inList})",
            $creds
        );

        if (!$result['success'] || empty($result['rows'])) {
            return [];
        }

        return self::mapForeignKeyRows($result['rows']);
    }

    /**
     * Build the " ON DELETE x ON UPDATE y" DDL fragment for a captured FK,
     * omitting the RESTRICT clause (the implicit default — mirrors how MySQL
     * itself omits it from SHOW CREATE TABLE output).
     *
     * @param array{delete_rule:string,update_rule:string} $fk
     * @return string
     */
    private static function foreignKeyOnClause(array $fk): string
    {
        $onDelete = $fk['delete_rule'] !== 'RESTRICT' ? " ON DELETE {$fk['delete_rule']}" : '';
        $onUpdate = $fk['update_rule'] !== 'RESTRICT' ? " ON UPDATE {$fk['update_rule']}" : '';
        return $onDelete . $onUpdate;
    }

    /**
     * Build a single `ALTER TABLE ... DROP FOREIGN KEY ...;` statement for a captured FK.
     *
     * @param array{table:string,constraint:string} $fk
     * @return string
     */
    private static function foreignKeyDropStatement(array $fk): string
    {
        return 'ALTER TABLE ' . Identifier::quote($fk['table']) . ' DROP FOREIGN KEY ' . Identifier::quote($fk['constraint']) . ';';
    }

    /**
     * Build a single `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ...;` statement for a captured FK.
     *
     * @param array{table:string,constraint:string,column:string,ref_table:string,ref_column:string,delete_rule:string,update_rule:string} $fk
     * @return string
     */
    private static function foreignKeyAddStatement(array $fk): string
    {
        return 'ALTER TABLE ' . Identifier::quote($fk['table']) . ' ADD CONSTRAINT ' . Identifier::quote($fk['constraint'])
            . ' FOREIGN KEY (' . Identifier::quote($fk['column']) . ') REFERENCES ' . Identifier::quote($fk['ref_table'])
            . ' (' . Identifier::quote($fk['ref_column']) . ')' . self::foreignKeyOnClause($fk) . ';';
    }

    // =========================================================================
    // Orphan cleanup
    // =========================================================================

    /**
     * Detect and clean up orphaned _bak_* tables from a previous failed restore.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name
     * @param string $bakPrefix Backup table prefix
     * @return int Number of orphaned tables found and cleaned up
     */
    private function cleanupOrphanedBakTables(array $creds, string $dbName, string $bakPrefix): int
    {
        $result = ExecHelper::mysqlQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_type = 'BASE TABLE' AND table_name LIKE '{$bakPrefix}%'",
            $creds
        );

        $bakTables = [];
        if ($result['success'] && !empty($result['rows'])) {
            $bakTables = array_map(fn ($r) => $r[0], $result['rows']);
        }

        if (empty($bakTables)) {
            return 0;
        }

        $this->log("[Restore/InPlace] Found " . count($bakTables) . " orphaned {$bakPrefix} tables from a previous failed restore, cleaning up", 'WARNING');

        // Drop FK constraints from _bak_ tables first. The DROP statements are
        // built here in PHP from the raw TABLE_NAME/CONSTRAINT_NAME columns —
        // NOT via SQL-side CONCAT('ALTER TABLE `', TABLE_NAME, ...) — because
        // these names originate from a restored (untrusted) database, and
        // CONCAT never doubles an embedded backtick the way Identifier::quote()
        // does, making it a DDL-injection vector for a maliciously-named table
        // or constraint.
        $fkResult = ExecHelper::mysqlQuery(
            "SELECT TABLE_NAME, CONSTRAINT_NAME " .
            "FROM information_schema.TABLE_CONSTRAINTS " .
            "WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME LIKE '{$bakPrefix}%' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            $creds
        );

        if ($fkResult['success'] && !empty($fkResult['rows'])) {
            $fkDropStatements = array_map(
                fn ($r) => 'ALTER TABLE ' . Identifier::quote($r[0]) . ' DROP FOREIGN KEY ' . Identifier::quote($r[1]) . ';',
                $fkResult['rows']
            );
            $fkDropSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', $fkDropStatements) . " SET FOREIGN_KEY_CHECKS = 1;";
            ExecHelper::mysqlExec($fkDropSQL, $creds, $dbName);
        }

        // Drop the _bak_ tables
        $drops = [];
        foreach ($bakTables as $table) {
            $drops[] = Identifier::quote($table);
        }
        $dropSQL = "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS " . implode(', ', $drops) . "; SET FOREIGN_KEY_CHECKS = 1;";

        $dropResult = ExecHelper::mysqlExec($dropSQL, $creds, $dbName);

        if (!$dropResult['success']) {
            $this->log("[Restore/InPlace] Failed to clean up orphaned {$bakPrefix} tables: " . $dropResult['error'], 'WARNING');
        } else {
            $this->log("[Restore/InPlace] Cleaned up " . count($bakTables) . " orphaned {$bakPrefix} tables", 'DEBUG');
        }

        return count($bakTables);
    }

    /**
     * Detect and clean up orphaned _restore_* and _old_* databases from a
     * previous failed or crashed atomic restore attempt.
     *
     * Unlike the in-place strategy's `_bak_*` tables (always pure debris — the
     * real recovery mechanism there is a separate rollback snapshot), the
     * atomic strategy's `_old_{timestamp}` database IS the deliberate recovery
     * target when the FK-rebuild step hard-fails (no separate snapshot exists
     * for this strategy). So the two temp-database families need different rules:
     * - `_restore_{timestamp}`: never holds recovery value (empty post-swap;
     *   duplicates live data pre-swap) but MIGHT still belong to a genuinely
     *   concurrent restore attempt (there is no app-level lock around the
     *   restore chain) — swept only once older than ORPHANED_RESTORE_DB_MIN_AGE_SECONDS.
     * - `_old_{timestamp}`: the admin's manual-recovery copy on hard-fail —
     *   swept only once older than ORPHANED_RECOVERY_DB_GRACE_HOURS, giving a
     *   real window to notice and recover before it's reclaimed.
     * Both branches require a strict `Ymd_His` round-trip parse of the trailing
     * timestamp before ever considering a drop — an unparseable name is always
     * kept, never guessed at.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name being restored
     * @return int Number of orphaned databases found and dropped
     */
    private function cleanupOrphanedRestoreDatabases(array $creds, string $dbName): int
    {
        $result = ExecHelper::mysqlQuery(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '{$dbName}_restore_%' OR SCHEMA_NAME LIKE '{$dbName}_old_%'",
            $creds
        );

        if (!$result['success']) {
            $this->log("[Restore/Atomic] Failed to query for orphaned restore databases: " . ($result['error'] ?? 'unknown'), 'WARNING');
            return 0;
        }

        if (empty($result['rows'])) {
            return 0;
        }

        $restorePrefix = "{$dbName}_restore_";
        $oldPrefix = "{$dbName}_old_";
        $dropped = 0;
        $preservedOld = 0;

        foreach ($result['rows'] as $row) {
            $schema = $row[0];

            if (str_starts_with($schema, $restorePrefix)) {
                $ts = substr($schema, strlen($restorePrefix));
                $minAge = self::ORPHANED_RESTORE_DB_MIN_AGE_SECONDS;
            } elseif (str_starts_with($schema, $oldPrefix)) {
                $ts = substr($schema, strlen($oldPrefix));
                $minAge = self::ORPHANED_RECOVERY_DB_GRACE_HOURS * 3600;
            } else {
                // Only reachable via a LIKE-wildcard false positive (literal `_` in $dbName).
                $this->log("[Restore/Atomic] Skipping unexpected SCHEMATA match: {$schema}", 'WARNING');
                continue;
            }

            // Strict round-trip parse — createFromFormat is lenient and would
            // otherwise silently accept a malformed suffix. No explicit timezone
            // argument: this must match date('Ymd_His')'s implicit
            // default-timezone behavior used to generate the name, so the age
            // delta below isn't skewed by a timezone mismatch.
            $dt = \DateTime::createFromFormat('Ymd_His', $ts);
            if ($dt === false || $dt->format('Ymd_His') !== $ts) {
                $this->log("[Restore/Atomic] Could not parse timestamp of {$schema}, keeping it (never guess)", 'WARNING');
                continue;
            }

            $ageSeconds = time() - $dt->getTimestamp();
            if ($ageSeconds < $minAge) {
                if (str_starts_with($schema, $oldPrefix)) {
                    $preservedOld++;
                }
                continue;
            }

            $dropResult = ExecHelper::mysqlExec(
                'SET FOREIGN_KEY_CHECKS = 0; DROP DATABASE IF EXISTS ' . Identifier::quote($schema) . '; SET FOREIGN_KEY_CHECKS = 1;',
                $creds
            );

            if (!$dropResult['success']) {
                $this->log("[Restore/Atomic] Failed to drop orphaned database {$schema}: " . ($dropResult['error'] ?? 'unknown'), 'WARNING');
                continue;
            }

            $dropped++;
            if (str_starts_with($schema, $oldPrefix)) {
                $this->log("[Restore/Atomic] Dropped stale recovery database {$schema} (past the " . self::ORPHANED_RECOVERY_DB_GRACE_HOURS . "h grace window)", 'WARNING');
            } else {
                $this->log("[Restore/Atomic] Dropped orphaned restore database {$schema}", 'DEBUG');
            }
        }

        if ($preservedOld > 0) {
            $this->log("[Restore/Atomic] {$preservedOld} recovery database(s) preserved within the grace window", 'DEBUG');
        }

        return $dropped;
    }

    // =========================================================================
    // Privilege / integrity helpers
    // =========================================================================

    /**
     * Check if the DB user has CREATE DATABASE privilege.
     *
     * @param array $creds Database credentials
     * @return bool True if user can create databases
     */
    private function hasCreateDbPrivilege(array $creds): bool
    {
        $result = ExecHelper::mysqlQuery("SHOW GRANTS FOR CURRENT_USER()", $creds);

        if (!$result['success']) {
            return false;
        }

        $grantLines = array_map(fn ($r) => $r[0] ?? '', $result['rows']);
        $grants = implode(' ', $grantLines);

        if (preg_match('/ALL PRIVILEGES ON \*\.\*/', $grants)) {
            return true;
        }

        if (preg_match('/\bCREATE\b.*ON \*\.\*/', $grants)) {
            return true;
        }

        return false;
    }

    /**
     * Find the first of the configured critical tables that is present but has
     * zero rows in the given database — a signal that the import completed
     * without a per-statement error yet silently produced an unusable table.
     * Returns null immediately (no check performed) when no critical tables
     * are configured — see {@see __construct()}.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name to check (may be a temp restore database)
     * @param array $availableTables Table names known to exist in $dbName
     * @return string|null The first empty critical table name, or null if none are empty
     */
    private function findEmptyCriticalTable(array $creds, string $dbName, array $availableTables): ?string
    {
        foreach ($this->criticalTables as $table) {
            if (!in_array($table, $availableTables, true)) {
                continue;
            }
            $result = ExecHelper::mysqlQuery('SELECT COUNT(*) FROM ' . Identifier::quote($table), $creds, $dbName);
            $rowCount = ($result['success'] && isset($result['rows'][0][0])) ? (int) $result['rows'][0][0] : 0;
            if ($rowCount === 0) {
                return $table;
            }
        }
        return null;
    }

    // =========================================================================
    // Public entry points
    // =========================================================================

    /**
     * Restore database from a backup archive.
     *
     * Tries atomic restore via temp database first (requires CREATE DATABASE
     * privilege). Falls back to in-place restore (table rename within same
     * database) when the DB user lacks global CREATE/DROP DATABASE rights.
     *
     * @param int $backupId Backup record ID
     * @param array|null $credentials Optional DB credentials for different-server restore
     * @return array{success: bool, error: ?string}
     */
    public function restoreDatabase(int $backupId, ?array $credentials = null): array
    {
        $backup = $this->backupEngine->getBackup($backupId);
        if (!$backup) {
            return ['success' => false, 'error' => 'Backup not found'];
        }

        if ($backup->file_deleted_at !== null) {
            return ['success' => false, 'error' => 'Backup file has been deleted'];
        }

        $backupPath = $this->backupEngine->getBackupDir() . '/' . $backup->filename;
        if (!file_exists($backupPath)) {
            return ['success' => false, 'error' => 'Backup file not found on disk'];
        }

        $this->log("[Restore] Starting database restore: backup_id={$backupId}, file={$backup->filename}", 'DEBUG');
        $result = $this->restoreFromArchivePath($backupPath, $backup, $credentials);

        // The mysqldump was captured while this backup's status was still
        // 'in_progress'. Now that the restore succeeded, fix the record to
        // reflect its actual completed state.
        if ($result['success']) {
            try {
                $this->backupEngine->markBackupCompletedIfInProgress($backupId);
            } catch (\Throwable $e) {
                $this->log("[Restore] Could not fix backup status post-restore: " . $e->getMessage(), 'WARNING');
            }
        }

        return $result;
    }

    /**
     * Restore the database from an arbitrary archive path (no bookkeeping lookup).
     *
     * Generic entry point for restoring a module-format .tgz archive that
     * lives outside the regular backup dir. Reads the manifest embedded in
     * the archive to detect partial dumps.
     *
     * @param string $archivePath Absolute path to the .tgz backup archive
     * @param array|null $credentials Optional DB credentials
     * @return array{success: bool, error: ?string}
     */
    public function restoreFromPath(string $archivePath, ?array $credentials = null): array
    {
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Archive file not found: ' . $archivePath];
        }

        // Synthesize a minimal $backup object (tables_count=0 -> guard is relaxed for partial)
        $backup = new \stdClass();
        $backup->tables_count = 0;
        $backup->file_deleted_at = null;

        $this->log("[Restore] Starting restore from path: {$archivePath}", 'INFO');
        return $this->restoreFromArchivePath($archivePath, $backup, $credentials);
    }

    /**
     * Core restore implementation shared by restoreDatabase() and restoreFromPath().
     *
     * @param string $backupPath Absolute path to the archive
     * @param object $backup Backup metadata (only tables_count used for guard)
     * @param array|null $credentials Optional DB credentials
     * @return array{success: bool, error: ?string}
     */
    private function restoreFromArchivePath(string $backupPath, object $backup, ?array $credentials = null): array
    {
        // Initialize progress with common steps (strategy-specific steps added later)
        $this->initProgress(['verify_archive', 'extract_dump']);
        $this->stepProgress('verify_archive');

        $integrity = $this->backupEngine->verifyArchiveIntegrity($backupPath);
        if (!$integrity['valid']) {
            $this->failProgress('verify_archive');
            $this->log("[Restore] Archive integrity check failed: {$integrity['error']}", 'DEBUG');
            return ['success' => false, 'error' => 'Archive integrity check failed: ' . $integrity['error']];
        }

        if (!$integrity['has_database']) {
            $this->failProgress('verify_archive');
            $this->log("[Restore] Archive does not contain a database dump", 'DEBUG');
            return ['success' => false, 'error' => 'Archive does not contain a database dump'];
        }

        $this->log("[Restore] Archive integrity verified", 'DEBUG');

        set_time_limit(600);

        $creds = $credentials ?? $this->backupEngine->getDbCredentials();
        $this->logDatabaseSizeEstimate($creds);
        // $timestamp feeds the `_restore_<ts>`/`_old_<ts>` database names built in
        // restoreDatabaseAtomic() below — it MUST stay a bare Ymd_His string, since
        // cleanupOrphanedRestoreDatabases() strictly round-trip-parses it for
        // age-based reclaim. The extraction workdir gets its own random suffix.
        $timestamp = date('Ymd_His');
        $extractDir = $this->tempPath . '/restore_db_' . $timestamp . '_' . bin2hex(random_bytes(6));

        try {
            // Step 1: Extract database dump from archive
            $this->stepProgress('extract_dump');
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0775, true);
            }

            // Both extractions' results are checked — a refused (e.g. zip-slip
            // member detected) or otherwise failed extraction must surface its
            // real reason, not silently fall through to the generic "no SQL
            // dump found" below.
            $dbExtract = ExecHelper::tarExtract($backupPath, $extractDir, './database/*');
            if (!$dbExtract['success']) {
                $this->failProgress('extract_dump');
                $this->log("[Restore] Failed to extract database dump: " . ($dbExtract['error'] ?? 'unknown'), 'DEBUG');
                return ['success' => false, 'error' => 'Failed to extract database dump: ' . ($dbExtract['error'] ?? 'unknown')];
            }
            $manifestExtract = ExecHelper::tarExtract($backupPath, $extractDir, './manifest.json');
            if (!$manifestExtract['success']) {
                $this->failProgress('extract_dump');
                $this->log("[Restore] Failed to extract manifest: " . ($manifestExtract['error'] ?? 'unknown'), 'DEBUG');
                return ['success' => false, 'error' => 'Failed to extract manifest: ' . ($manifestExtract['error'] ?? 'unknown')];
            }

            $sqlFiles = glob($extractDir . '/database/*.sql');
            if (empty($sqlFiles)) {
                $this->log("[Restore] No SQL dump found in extracted archive at {$extractDir}/database/", 'DEBUG');
                throw new \RuntimeException('No SQL dump found in archive');
            }
            $sqlDumpPath = $sqlFiles[0];
            $sqlDumpSize = filesize($sqlDumpPath);
            $this->log("[Restore] SQL dump extracted: {$sqlDumpPath} (" . number_format($sqlDumpSize) . " bytes)", 'DEBUG');

            // Disk-space check BEFORE any destructive step (rename/swap/import).
            $spaceCheck = $this->ensureDiskSpace($sqlDumpSize, 'database restore');
            if (!$spaceCheck['success']) {
                $this->failProgress('extract_dump');
                return ['success' => false, 'error' => $spaceCheck['error']];
            }

            // Read manifest for partial-restore metadata. A corrupt or unreadable
            // manifest must abort the restore — silently ignoring it would promote
            // a partial dump to a full DB replace, destroying non-managed tables.
            $managedTables = null;
            $manifestPath = $extractDir . '/manifest.json';
            if (file_exists($manifestPath)) {
                $raw = file_get_contents($manifestPath);
                if ($raw === false) {
                    throw new \RuntimeException('Failed to read manifest.json from archive — cannot determine restore scope');
                }
                $manifestData = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Archive manifest.json is malformed (' . json_last_error_msg() . ') — cannot determine restore scope');
                }
                if (!empty($manifestData['database']['partial']) && !empty($manifestData['database']['partial_tables'])) {
                    $managedTables = $manifestData['database']['partial_tables'];
                    $this->log("[Restore] Partial backup detected; scoping restore to " . count($managedTables) . " tables", 'INFO');
                }
            }

            // Step 2: Choose restore strategy — atomic preferred, in-place as fallback
            if ($this->hasCreateDbPrivilege($creds)) {
                $this->log('Database restore: using atomic strategy (CREATE DATABASE available)', 'INFO');
                $this->initProgress(['verify_archive', 'extract_dump', 'create_temp_db', 'import_db', 'verify_data', 'swap_databases', 'finalize']);
                $this->stepProgress('create_temp_db');
                $result = $this->restoreDatabaseAtomic($creds, $sqlDumpPath, $backup, $timestamp, $managedTables);

                if (!$result['success']) {
                    $this->log("Database restore: atomic strategy failed ({$result['error']}), falling back to in-place strategy", 'WARNING');
                    $this->initProgress(['verify_archive', 'extract_dump', 'create_snapshot', 'prepare_tables', 'import_db', 'verify_data', 'finalize']);
                    $this->stepProgress('create_snapshot');
                    $result = $this->restoreDatabaseInPlace($creds, $sqlDumpPath, $backup, $timestamp, $managedTables);
                }
            } else {
                $this->log('Database restore: using in-place strategy (no CREATE DATABASE privilege)', 'INFO');
                $this->initProgress(['verify_archive', 'extract_dump', 'create_snapshot', 'prepare_tables', 'import_db', 'verify_data', 'finalize']);
                $this->stepProgress('create_snapshot');
                $result = $this->restoreDatabaseInPlace($creds, $sqlDumpPath, $backup, $timestamp, $managedTables);
            }

            if ($result['success']) {
                $this->completeProgress();
            }

            Fs::removeDirectory($extractDir);

            return $result;
        } catch (\Throwable $e) {
            Fs::removeDirectory($extractDir);
            $this->log('Database restore failed: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Atomic database restore using temporary databases and RENAME TABLE swap.
     *
     * Requires CREATE/DROP DATABASE privileges. The original database remains
     * untouched if any step fails.
     *
     * @param array $creds Database credentials
     * @param string $sqlDumpPath Path to extracted SQL dump file
     * @param object $backup Backup record
     * @param string $timestamp Timestamp for unique naming
     * @param array|null $managedTables
     * @return array{success: bool, error: ?string}
     */
    private function restoreDatabaseAtomic(array $creds, string $sqlDumpPath, object $backup, string $timestamp, ?array $managedTables = null): array
    {
        // Fail fast, before any destructive operation, if the host-supplied
        // database name doesn't match the safe-identifier whitelist. It is
        // host-configured rather than archive-derived, but the facade never
        // enforces this shape at construction time, so it is validated here.
        try {
            Identifier::assertValid($creds['database']);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $restoreDbName = $creds['database'] . '_restore_' . $timestamp;
        $oldDbName = $creds['database'] . '_old_' . $timestamp;

        $this->log("[Restore/Atomic] Starting: restore_db={$restoreDbName}, old_db={$oldDbName}", 'DEBUG');

        try {
            // Clean up orphaned _restore_*/_old_* databases from previous failed/crashed restores
            $this->cleanupOrphanedRestoreDatabases($creds, $creds['database']);

            // Create temp database
            $this->log("[Restore/Atomic] Creating temporary database: {$restoreDbName}", 'DEBUG');
            $createResult = ExecHelper::mysqlExec(
                'CREATE DATABASE ' . Identifier::quote($restoreDbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $creds
            );
            if (!$createResult['success']) {
                $this->log("[Restore/Atomic] CREATE DATABASE failed: " . $createResult['error'], 'DEBUG');
                return ['success' => false, 'error' => 'Failed to create temporary database: ' . $createResult['error']];
            }
            $this->log("[Restore/Atomic] Temporary database created", 'DEBUG');

            // Strip DEFINER clauses from dump file so non-root users can import
            $this->stepProgress('import_db');
            $this->stripDefinerFromSqlFile($sqlDumpPath);

            // Import dump into temp database
            $this->log("[Restore/Atomic] Importing SQL dump into {$restoreDbName}", 'DEBUG');
            $importResult = ExecHelper::mysqlImport($creds, $restoreDbName, $sqlDumpPath);
            if (!$importResult['success']) {
                $this->failProgress('import_db');
                throw new \RuntimeException('Failed to import database dump: ' . $importResult['error']);
            }
            $this->log("[Restore/Atomic] SQL dump imported successfully", 'DEBUG');

            // Verify integrity - compare table count
            $this->stepProgress('verify_data');
            $this->log("[Restore/Atomic] Verifying table count", 'DEBUG');
            $countResult = ExecHelper::mysqlQuery(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$restoreDbName}'",
                $creds
            );
            $restoredTableCount = (int) ($countResult['rows'][0][0] ?? 0);
            $this->log("[Restore/Atomic] Table count: restored={$restoredTableCount}, expected={$backup->tables_count}", 'DEBUG');

            // For partial restores the expected count is the partial dump count, not the full DB count
            $isPartial = $managedTables !== null;
            if (!$isPartial && $backup->tables_count && $restoredTableCount < (int) $backup->tables_count) {
                $this->failProgress('verify_data');
                throw new \RuntimeException("Table count mismatch: expected {$backup->tables_count}, got {$restoredTableCount}");
            }

            // Row-count sanity for the tables the site's own reachability depends on.
            // Only for a FULL restore — a partial restore's $managedTables may
            // legitimately exclude the configured critical tables. Runs entirely
            // inside the isolated $restoreDbName, before anything live is touched.
            if (!$isPartial) {
                $restoreDbTablesResult = ExecHelper::mysqlQuery(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$restoreDbName}' AND table_type = 'BASE TABLE'",
                    $creds
                );
                $restoreDbTables = $restoreDbTablesResult['success'] ? array_map(fn ($r) => $r[0], $restoreDbTablesResult['rows']) : [];
                $emptyCriticalTable = $this->findEmptyCriticalTable($creds, $restoreDbName, $restoreDbTables);
                if ($emptyCriticalTable !== null) {
                    $this->failProgress('verify_data');
                    throw new \RuntimeException("Restore verification failed: critical table '{$emptyCriticalTable}' is empty after import");
                }
            }

            // Get list of tables in both databases for RENAME
            $currentResult = ExecHelper::mysqlQuery(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$creds['database']}' AND table_type = 'BASE TABLE'",
                $creds
            );
            $allCurrentTables = $currentResult['success'] ? array_map(fn ($r) => $r[0], $currentResult['rows']) : [];

            // For partial restores, only move the managed tables out of the live DB
            $currentTables = $isPartial
                ? array_values(array_intersect($allCurrentTables, $managedTables))
                : $allCurrentTables;

            // For partial restores: MariaDB auto-updates FK references in non-managed
            // tables (even across databases) when a referenced table is renamed away.
            // Capture and drop those external FKs now so they can be rebuilt once the
            // managed tables are back under their permanent names after the swap.
            // Mirrors the same protection in restoreDatabaseInPlace().
            $externalFksToRebuild = [];
            if ($isPartial && !empty($currentTables)) {
                $externalFksToRebuild = $this->queryExternalInboundForeignKeys($creds, $creds['database'], $currentTables);
                if (!empty($externalFksToRebuild)) {
                    $this->log("[Restore/Atomic] Dropping " . count($externalFksToRebuild) . " external FK constraints referencing managed tables", 'DEBUG');
                    $extDropSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', array_map([self::class, 'foreignKeyDropStatement'], $externalFksToRebuild)) . " SET FOREIGN_KEY_CHECKS = 1;";
                    ExecHelper::mysqlExec($extDropSQL, $creds, $creds['database']);
                }
            }

            // Outbound direction: FKs FROM a managed table TO a non-managed table.
            // The golden dump's own CREATE TABLE statement for the managed table
            // still defines these constraints, so mysqlImport recreates them
            // inside the isolated $restoreDbName — pointing at a same-named table
            // that does not exist there. RENAME TABLE does not fix this: it only
            // updates FKs that point AT a renamed table, not FKs defined ON it.
            // Capture the correct target from the live DB now, then drop+recreate
            // it against the live table after the swap.
            $outboundFksToRebuild = [];
            if ($isPartial && !empty($currentTables)) {
                $outboundFksToRebuild = $this->queryOutboundForeignKeys($creds, $creds['database'], $currentTables);
            }

            $restoreResult = ExecHelper::mysqlQuery(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$restoreDbName}' AND table_type = 'BASE TABLE'",
                $creds
            );
            $restoreTables = $restoreResult['success'] ? array_map(fn ($r) => $r[0], $restoreResult['rows']) : [];
            $this->log("[Restore/Atomic] Tables: current-managed=" . count($currentTables) . ", restore=" . count($restoreTables), 'DEBUG');

            // ALWAYS verify (partial and full) BEFORE the swap, while the live DB is
            // still intact. Expected set: managed tables for partial, or the
            // pre-restore live tables for full. Fail-closed on an empty expected
            // set so an unverifiable archive never swaps in.
            $expectedTables = $isPartial ? array_values($managedTables) : $allCurrentTables;
            if (empty($expectedTables)) {
                $this->failProgress('verify_data');
                throw new \RuntimeException('Restore verification impossible: expected table set is empty');
            }
            $missingTables = array_values(array_diff($expectedTables, $restoreTables));
            if (!empty($missingTables)) {
                $this->failProgress('verify_data');
                throw new \RuntimeException('Restore verification failed: ' . count($missingTables) . ' expected table(s) missing in the imported dump');
            }

            // Create old database for table swap
            $this->stepProgress('swap_databases');
            $this->log("[Restore/Atomic] Creating old database for swap: {$oldDbName}", 'DEBUG');
            $oldDbResult = ExecHelper::mysqlExec(
                'CREATE DATABASE ' . Identifier::quote($oldDbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $creds
            );
            if (!$oldDbResult['success']) {
                throw new \RuntimeException('Failed to create old database for swap');
            }

            // Build atomic RENAME TABLE statement. Db names ($creds['database'],
            // $oldDbName, $restoreDbName) were assertValid()'d/derived-from-validated
            // above; $table is untrusted (from information_schema of the imported
            // database) and must be backtick-quoted.
            $renames = [];
            foreach ($currentTables as $table) {
                $renames[] = Identifier::quote($creds['database']) . '.' . Identifier::quote($table) . ' TO ' . Identifier::quote($oldDbName) . '.' . Identifier::quote($table);
            }
            foreach ($restoreTables as $table) {
                $renames[] = Identifier::quote($restoreDbName) . '.' . Identifier::quote($table) . ' TO ' . Identifier::quote($creds['database']) . '.' . Identifier::quote($table);
            }

            if (empty($renames)) {
                throw new \RuntimeException('No tables found to swap');
            }

            // Save and drop triggers from BOTH databases before cross-DB rename
            // (MySQL does not allow RENAME TABLE across databases when tables have
            // triggers). Scoped to $currentTables/$restoreTables (the tables
            // actually being swapped) so a trigger on an untouched table is never
            // captured/dropped in the first place.
            $this->log("[Restore/Atomic] Saving triggers before cross-database rename", 'DEBUG');
            $currentTriggers = $this->getDatabaseTriggers($creds, $creds['database'], $currentTables);
            $restoreTriggers = $this->getDatabaseTriggers($creds, $restoreDbName, $restoreTables);

            if (!empty($currentTriggers)) {
                $this->log("[Restore/Atomic] Dropping " . count($currentTriggers) . " triggers from current database", 'DEBUG');
                $this->dropDatabaseTriggers($creds, $creds['database'], $currentTriggers);
            }
            if (!empty($restoreTriggers)) {
                $this->log("[Restore/Atomic] Dropping " . count($restoreTriggers) . " triggers from restore database", 'DEBUG');
                $this->dropDatabaseTriggers($creds, $restoreDbName, $restoreTriggers);
            }

            // Disable foreign key checks for the swap
            $this->log("[Restore/Atomic] Executing atomic RENAME TABLE swap (" . count($renames) . " operations)", 'DEBUG');
            $renameSQL = "SET FOREIGN_KEY_CHECKS = 0; RENAME TABLE " . implode(', ', $renames) . "; SET FOREIGN_KEY_CHECKS = 1;";

            $renameResult = ExecHelper::mysqlExec($renameSQL, $creds);

            if (!$renameResult['success']) {
                $this->failProgress('swap_databases');
                $this->log("[Restore/Atomic] RENAME failed, restoring current database triggers", 'DEBUG');
                if (!empty($currentTriggers)) {
                    $this->recreateDatabaseTriggers($creds, $creds['database'], $currentTriggers);
                }
                throw new \RuntimeException('Atomic table swap failed: ' . $renameResult['error']);
            }
            $this->log("[Restore/Atomic] Table swap completed successfully", 'DEBUG');

            // Recreate triggers from the restore DB in the current DB (tables are now swapped)
            if (!empty($restoreTriggers)) {
                $this->log("[Restore/Atomic] Recreating " . count($restoreTriggers) . " triggers in current database", 'DEBUG');
                $triggerErrors = $this->recreateDatabaseTriggers($creds, $creds['database'], $restoreTriggers);
                if ($triggerErrors > 0) {
                    $this->log("[Restore/Atomic] WARNING: {$triggerErrors} triggers failed to recreate", 'WARNING');
                }
            }

            // Rebuild external FK constraints captured before the swap. The managed
            // tables now live under their permanent names again, so the
            // referenced-table name captured pre-swap is valid once more.
            // HARD FAIL on rebuild failure: {$oldDbName} is deliberately NOT
            // dropped in that case, leaving the pre-restore tables available for
            // manual recovery — mirrors the hard-fail behavior in
            // restoreDatabaseInPlace().
            if (!empty($externalFksToRebuild)) {
                $this->log("[Restore/Atomic] Rebuilding " . count($externalFksToRebuild) . " external FK constraints", 'DEBUG');
                $rebuildSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', array_map([self::class, 'foreignKeyAddStatement'], $externalFksToRebuild)) . " SET FOREIGN_KEY_CHECKS = 1;";
                $rebuildResult = ExecHelper::mysqlExec($rebuildSQL, $creds, $creds['database']);
                if (!($rebuildResult['success'] ?? false)) {
                    $this->log("[Restore/Atomic] External FK rebuild FAILED, leaving {$oldDbName} and {$restoreDbName} in place for manual recovery: " . ($rebuildResult['error'] ?? 'unknown'), 'ERROR');
                    $this->failProgress('finalize');
                    return [
                        'success' => false,
                        'error' => 'Failed to rebuild foreign key constraints after restore: ' . ($rebuildResult['error'] ?? 'unknown') .
                            ' — ' . $this->t->translate('TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                    ];
                }
            }

            // Fix up outbound FK constraints captured before the swap. The managed
            // table's constraint currently references `{$restoreDbName}` (baked in
            // by the isolated import) — drop and recreate it unqualified so it
            // resolves against the live schema's already-correct, untouched
            // non-managed table. Same hard-fail contract as the inbound rebuild above.
            if (!empty($outboundFksToRebuild)) {
                $this->log("[Restore/Atomic] Fixing " . count($outboundFksToRebuild) . " outbound FK constraints on managed tables", 'DEBUG');
                $outFixStatements = [];
                foreach ($outboundFksToRebuild as $fk) {
                    $outFixStatements[] = self::foreignKeyDropStatement($fk);
                    $outFixStatements[] = self::foreignKeyAddStatement($fk);
                }
                $outFixSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', $outFixStatements) . " SET FOREIGN_KEY_CHECKS = 1;";
                $outFixResult = ExecHelper::mysqlExec($outFixSQL, $creds, $creds['database']);
                if (!($outFixResult['success'] ?? false)) {
                    $this->log("[Restore/Atomic] Outbound FK fix FAILED, leaving {$oldDbName} and {$restoreDbName} in place for manual recovery: " . ($outFixResult['error'] ?? 'unknown'), 'ERROR');
                    $this->failProgress('finalize');
                    return [
                        'success' => false,
                        'error' => 'Failed to fix outbound foreign key constraints after restore: ' . ($outFixResult['error'] ?? 'unknown') .
                            ' — ' . $this->t->translate('TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                    ];
                }
            }

            // Drop old database (cleanup)
            $this->stepProgress('finalize');
            $this->log("[Restore/Atomic] Cleaning up temporary databases", 'DEBUG');
            ExecHelper::mysqlExec('DROP DATABASE IF EXISTS ' . Identifier::quote($oldDbName), $creds);

            // Drop restore database (should be empty after swap)
            ExecHelper::mysqlExec('DROP DATABASE IF EXISTS ' . Identifier::quote($restoreDbName), $creds);

            $this->log("[Restore/Atomic] Database restore completed successfully", 'DEBUG');
            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            // Cleanup: drop temporary databases if they exist
            ExecHelper::mysqlExec(
                'DROP DATABASE IF EXISTS ' . Identifier::quote($restoreDbName) . '; DROP DATABASE IF EXISTS ' . Identifier::quote($oldDbName),
                $creds
            );

            $this->log('Atomic database restore failed: ' . $e->getMessage(), 'ERROR');

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * In-place database restore using table rename within the same database.
     *
     * Used when the DB user lacks CREATE/DROP DATABASE privileges. Renames
     * current tables to _bak_ prefix, imports the dump, verifies, then drops
     * backup tables. On failure, restores original tables from _bak_ prefix.
     *
     * @param array $creds Database credentials
     * @param string $sqlDumpPath Path to extracted SQL dump file
     * @param object $backup Backup record
     * @param string $timestamp Timestamp for unique naming
     * @param array|null $managedTables
     * @return array{success: bool, error: ?string, rolled_back?: bool} `rolled_back`
     *         is only present when a rollback-to-snapshot was actually attempted.
     */
    private function restoreDatabaseInPlace(array $creds, string $sqlDumpPath, object $backup, string $timestamp, ?array $managedTables = null): array
    {
        // Fail fast, before any destructive operation — see the identical
        // guard + reasoning in restoreDatabaseAtomic().
        try {
            Identifier::assertValid($creds['database']);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $bakPrefix = '_bak_';
        $dbName = $creds['database'];
        $isPartial = $managedTables !== null;

        $this->log("[Restore/InPlace] Starting in-place restore for database: {$dbName}" . ($isPartial ? ' (partial)' : ''), 'DEBUG');

        $snapshotPath = '';

        try {
            // Clean up orphaned _bak_* tables from a previous failed restore
            $this->cleanupOrphanedBakTables($creds, $dbName, $bakPrefix);

            // Get list of current tables — for partial restores, scope to managed tables only
            $tablesResult = ExecHelper::mysqlQuery(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_type = 'BASE TABLE' AND table_name NOT LIKE '{$bakPrefix}%'",
                $creds
            );
            $allCurrentTables = $tablesResult['success'] ? array_map(fn ($r) => $r[0], $tablesResult['rows']) : [];
            $currentTables = $isPartial
                ? array_values(array_intersect($allCurrentTables, $managedTables))
                : $allCurrentTables;
            $this->log("[Restore/InPlace] Found " . count($currentTables) . " current tables" . ($isPartial ? ' (partial scope)' : ''), 'DEBUG');

            if (!$isPartial && empty($currentTables)) {
                return ['success' => false, 'error' => 'No tables found in current database'];
            }

            // Create rollback snapshot (complete mysqldump for guaranteed recovery)
            $this->log("[Restore/InPlace] Creating rollback snapshot of current database", 'DEBUG');
            $snapshotPath = $this->createRollbackSnapshot($creds);
            if ($snapshotPath === false) {
                return ['success' => false, 'error' => 'Failed to create rollback snapshot — cannot guarantee safe restore'];
            }

            // For partial restores: MariaDB auto-updates FK references in
            // non-renamed tables when a referenced table is renamed (even with
            // FOREIGN_KEY_CHECKS = 0). Capture and drop those external FKs now so
            // they can be rebuilt after the restore completes pointing to the
            // freshly-restored tables.
            $externalFksToRebuild = [];
            if ($isPartial && !empty($currentTables)) {
                $externalFksToRebuild = $this->queryExternalInboundForeignKeys($creds, $dbName, $currentTables);
                if (!empty($externalFksToRebuild)) {
                    $this->log("[Restore/InPlace] Dropping " . count($externalFksToRebuild) . " external FK constraints referencing managed tables", 'DEBUG');
                    $extDropSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', array_map([self::class, 'foreignKeyDropStatement'], $externalFksToRebuild)) . " SET FOREIGN_KEY_CHECKS = 1;";
                    ExecHelper::mysqlExec($extDropSQL, $creds, $dbName);
                }
            }

            // Rename current tables to _bak_ prefix (atomic RENAME)
            $this->stepProgress('prepare_tables');
            $this->log("[Restore/InPlace] Renaming " . count($currentTables) . " tables to {$bakPrefix} prefix", 'DEBUG');
            $renames = [];
            foreach ($currentTables as $table) {
                $renames[] = Identifier::quote($dbName) . '.' . Identifier::quote($table) . ' TO ' . Identifier::quote($dbName) . '.' . Identifier::quote($bakPrefix . $table);
            }
            $renameSQL = "SET FOREIGN_KEY_CHECKS = 0; RENAME TABLE " . implode(', ', $renames) . "; SET FOREIGN_KEY_CHECKS = 1;";

            $renameResult = ExecHelper::mysqlExec($renameSQL, $creds);

            if (!$renameResult['success']) {
                $this->log("[Restore/InPlace] RENAME TABLE failed: " . $renameResult['error'], 'DEBUG');
                if ($snapshotPath && file_exists($snapshotPath)) {
                    unlink($snapshotPath);
                }
                return ['success' => false, 'error' => 'Failed to rename current tables: ' . $renameResult['error']];
            }
            $this->log("[Restore/InPlace] Tables renamed to {$bakPrefix} prefix successfully", 'DEBUG');

            // Drop FK constraints from _bak_ tables to free constraint names for import.
            // Built in PHP from raw TABLE_NAME/CONSTRAINT_NAME — see the identical
            // reasoning in cleanupOrphanedBakTables() above (CONCAT-built DDL does
            // not double embedded backticks, Identifier::quote() does).
            $this->log("[Restore/InPlace] Dropping FK constraints from {$bakPrefix} tables", 'DEBUG');
            $fkResult = ExecHelper::mysqlQuery(
                "SELECT TABLE_NAME, CONSTRAINT_NAME " .
                "FROM information_schema.TABLE_CONSTRAINTS " .
                "WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME LIKE '{$bakPrefix}%' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                $creds
            );

            if ($fkResult['success'] && !empty($fkResult['rows'])) {
                $fkDropStatements = array_map(
                    fn ($r) => 'ALTER TABLE ' . Identifier::quote($r[0]) . ' DROP FOREIGN KEY ' . Identifier::quote($r[1]) . ';',
                    $fkResult['rows']
                );
                $this->log("[Restore/InPlace] Dropping " . count($fkDropStatements) . " FK constraints", 'DEBUG');
                $fkDropSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', $fkDropStatements) . " SET FOREIGN_KEY_CHECKS = 1;";
                ExecHelper::mysqlExec($fkDropSQL, $creds, $dbName);
                $this->log("[Restore/InPlace] FK constraints dropped", 'DEBUG');
            } else {
                $this->log("[Restore/InPlace] No FK constraints to drop", 'DEBUG');
            }

            // Drop triggers from _bak_ tables to free trigger names for import
            // (RENAME TABLE moves triggers with the table but keeps their
            // original names). Scoped to the _bak_-renamed table names.
            $bakTableNames = array_map(static fn (string $t): string => $bakPrefix . $t, $currentTables);
            $bakTriggers = $this->getDatabaseTriggers($creds, $dbName, $bakTableNames);
            if (!empty($bakTriggers)) {
                $this->log("[Restore/InPlace] Dropping " . count($bakTriggers) . " triggers from {$bakPrefix} tables", 'DEBUG');
                $this->dropDatabaseTriggers($creds, $dbName, $bakTriggers);
            }

            // Strip DEFINER clauses from dump file so non-root users can import
            $this->stepProgress('import_db');
            $this->stripDefinerFromSqlFile($sqlDumpPath);

            // Import dump into the current database
            $this->log("[Restore/InPlace] Importing SQL dump into {$dbName}", 'DEBUG');
            $importResult = ExecHelper::mysqlImport($creds, $dbName, $sqlDumpPath);

            if (!$importResult['success']) {
                $this->log("[Restore/InPlace] Import failed, initiating rollback: " . $importResult['error'], 'ERROR');
                $this->failProgress('import_db');
                $rolledBack = $this->rollbackInPlaceRestore($creds, $dbName, $bakPrefix, $currentTables, $snapshotPath);
                return [
                    'success' => false,
                    'rolled_back' => $rolledBack,
                    'error' => 'Failed to import database dump: ' . $importResult['error'] . ' — ' . $this->t->translate($rolledBack
                        ? 'TEXT_BACKUP_ROLLBACK_DB_SUCCEEDED'
                        : 'TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                ];
            }
            $this->log("[Restore/InPlace] SQL dump imported successfully", 'DEBUG');

            // Verify integrity — ALWAYS run before dropping the _bak_* originals,
            // for both full and partial restores. Enumerate restored table names
            // (not just a count) so we can confirm every expected table actually
            // exists after import.
            $this->stepProgress('verify_data');
            $this->log("[Restore/InPlace] Verifying restored tables", 'DEBUG');
            $restoredResult = ExecHelper::mysqlQuery(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_type = 'BASE TABLE' AND table_name NOT LIKE '{$bakPrefix}%'",
                $creds
            );
            $restoredTables = $restoredResult['success'] ? array_map(fn ($r) => $r[0], $restoredResult['rows']) : [];
            $restoredTableCount = count($restoredTables);

            // Expected table set: the managed tables for a partial restore, or the
            // pre-restore table set for a full restore (a full restore must not
            // silently lose tables). This also covers restoreFromPath() where
            // tables_count is 0.
            $expectedTables = $isPartial ? array_values($managedTables) : $currentTables;

            // Fail-closed: if we cannot determine what to verify, do NOT proceed
            // to the destructive drop of the _bak_* originals — roll back to
            // protect live data.
            if (empty($expectedTables)) {
                $this->log("[Restore/InPlace] Verification impossible (empty expected table set); rolling back to protect live data", 'ERROR');
                $this->failProgress('verify_data');
                $rolledBack = $this->rollbackInPlaceRestore($creds, $dbName, $bakPrefix, $currentTables, $snapshotPath);
                return [
                    'success' => false,
                    'rolled_back' => $rolledBack,
                    'error' => 'Restore verification impossible: expected table set is empty' . ' — ' . $this->t->translate($rolledBack
                        ? 'TEXT_BACKUP_ROLLBACK_DB_SUCCEEDED'
                        : 'TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                ];
            }

            // Table-presence check (always runs, partial and full).
            $missingTables = array_values(array_diff($expectedTables, $restoredTables));
            if (!empty($missingTables)) {
                $this->log("[Restore/InPlace] Missing tables after import: " . implode(', ', array_slice($missingTables, 0, 10)), 'ERROR');
                $this->failProgress('verify_data');
                $rolledBack = $this->rollbackInPlaceRestore($creds, $dbName, $bakPrefix, $currentTables, $snapshotPath);
                return [
                    'success' => false,
                    'rolled_back' => $rolledBack,
                    'error' => 'Restore verification failed: ' . count($missingTables) . ' expected table(s) missing after import' . ' — ' . $this->t->translate($rolledBack
                        ? 'TEXT_BACKUP_ROLLBACK_DB_SUCCEEDED'
                        : 'TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                ];
            }

            // Additional count check for full restores that carry an expected count.
            $this->log("[Restore/InPlace] Table count: restored={$restoredTableCount}, expected={$backup->tables_count}", 'DEBUG');
            if (!$isPartial && $backup->tables_count && $restoredTableCount < (int) $backup->tables_count) {
                $this->log("[Restore/InPlace] Table count mismatch, initiating rollback", 'ERROR');
                $this->failProgress('verify_data');
                $rolledBack = $this->rollbackInPlaceRestore($creds, $dbName, $bakPrefix, $currentTables, $snapshotPath);
                return [
                    'success' => false,
                    'rolled_back' => $rolledBack,
                    'error' => "Table count mismatch: expected {$backup->tables_count}, got {$restoredTableCount}" . ' — ' . $this->t->translate($rolledBack
                        ? 'TEXT_BACKUP_ROLLBACK_DB_SUCCEEDED'
                        : 'TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                ];
            }

            // Row-count sanity for the tables the site's own reachability depends
            // on. Only for a FULL restore — a partial restore's $managedTables may
            // legitimately exclude the configured critical tables, where this
            // check would otherwise false-fail and trigger an unnecessary rollback.
            if (!$isPartial) {
                $emptyCriticalTable = $this->findEmptyCriticalTable($creds, $dbName, $restoredTables);
                if ($emptyCriticalTable !== null) {
                    $this->log("[Restore/InPlace] Critical table '{$emptyCriticalTable}' is empty after import, initiating rollback", 'ERROR');
                    $this->failProgress('verify_data');
                    $rolledBack = $this->rollbackInPlaceRestore($creds, $dbName, $bakPrefix, $currentTables, $snapshotPath);
                    return [
                        'success' => false,
                        'rolled_back' => $rolledBack,
                        'error' => "Restore verification failed: critical table '{$emptyCriticalTable}' is empty after import" . ' — ' . $this->t->translate($rolledBack
                            ? 'TEXT_BACKUP_ROLLBACK_DB_SUCCEEDED'
                            : 'TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                    ];
                }
            }

            // Success: drop all _bak_ tables and delete snapshot
            $this->stepProgress('finalize');
            $this->log("[Restore/InPlace] Verification passed, dropping " . count($currentTables) . " backup tables", 'DEBUG');
            $drops = [];
            foreach ($currentTables as $table) {
                $drops[] = Identifier::quote($bakPrefix . $table);
            }
            $dropSQL = "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS " . implode(', ', $drops) . "; SET FOREIGN_KEY_CHECKS = 1;";

            // Non-fatal: a failed drop only leaves orphaned _bak_* tables (no data
            // loss); cleanupOrphanedBakTables() clears them on the next restore.
            $dropResult = ExecHelper::mysqlExec($dropSQL, $creds, $dbName);
            if (!($dropResult['success'] ?? false)) {
                $this->log("[Restore/InPlace] Failed to drop backup tables (orphans left for next cleanup): " . ($dropResult['error'] ?? 'unknown'), 'WARNING');
            }

            // Rebuild external FK constraints that were dropped before the rename.
            // The snapshot still exists here, so a rebuild failure can safely roll
            // back to the original state (which includes the original FK constraints).
            if (!empty($externalFksToRebuild)) {
                $this->log("[Restore/InPlace] Rebuilding " . count($externalFksToRebuild) . " external FK constraints", 'DEBUG');
                $rebuildSQL = "SET FOREIGN_KEY_CHECKS = 0; " . implode(' ', array_map([self::class, 'foreignKeyAddStatement'], $externalFksToRebuild)) . " SET FOREIGN_KEY_CHECKS = 1;";
                $rebuildResult = ExecHelper::mysqlExec($rebuildSQL, $creds, $dbName);
                if (!($rebuildResult['success'] ?? false)) {
                    // HARD FAIL: leaving the DB without its FK constraints is
                    // referential corruption. Roll back to the snapshot rather
                    // than report success.
                    $this->log("[Restore/InPlace] External FK rebuild FAILED, initiating rollback: " . ($rebuildResult['error'] ?? 'unknown'), 'ERROR');
                    $this->failProgress('finalize');
                    $rolledBack = $this->rollbackInPlaceRestore($creds, $dbName, $bakPrefix, $currentTables, $snapshotPath);
                    return [
                        'success' => false,
                        'rolled_back' => $rolledBack,
                        'error' => 'Failed to rebuild foreign key constraints after restore: ' . ($rebuildResult['error'] ?? 'unknown') . ' — ' . $this->t->translate($rolledBack
                            ? 'TEXT_BACKUP_ROLLBACK_DB_SUCCEEDED'
                            : 'TEXT_BACKUP_ROLLBACK_DB_FAILED_MANUAL_RECOVERY'),
                    ];
                }
            }

            // Delete rollback snapshot — no longer needed
            if ($snapshotPath && file_exists($snapshotPath)) {
                unlink($snapshotPath);
                $this->log("[Restore/InPlace] Rollback snapshot deleted", 'DEBUG');
            }

            $this->log("[Restore/InPlace] Database restore completed successfully", 'DEBUG');
            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log('[Restore/InPlace] Database restore failed: ' . $e->getMessage(), 'ERROR');
            if ($snapshotPath && file_exists($snapshotPath)) {
                unlink($snapshotPath);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rollback an in-place restore using the rollback snapshot.
     *
     * Ordering rationale: never touch `_bak_*` until the snapshot import
     * (which restores ALL database objects — tables, FKs, triggers, routines,
     * events, views — atomically under the original names) has been verified
     * to succeed. `_bak_*` and the original names never collide (different
     * prefixes), and the forward restore already dropped `_bak_*`'s FK
     * constraints and triggers before import, so importing the snapshot into
     * the freed original names cannot collide with anything on `_bak_*`
     * either. `_bak_*` is therefore ALWAYS still intact if this method
     * returns false, giving an operator a deterministic, named set of tables
     * to recover from manually.
     *
     * @param array $creds Database credentials
     * @param string $dbName Database name
     * @param string $bakPrefix Backup table prefix
     * @param array $originalTables List of original table names
     * @param string $snapshotPath Path to the rollback snapshot SQL file
     * @return bool True if rollback succeeded, false if it failed
     */
    private function rollbackInPlaceRestore(array $creds, string $dbName, string $bakPrefix, array $originalTables, string $snapshotPath): bool
    {
        $this->log("[Restore/Rollback] Starting rollback for {$dbName}", 'DEBUG');

        if (!$snapshotPath || !file_exists($snapshotPath)) {
            $this->log(
                "[Restore/Rollback] CRITICAL: Rollback snapshot not found at {$snapshotPath} — " .
                'the ' . $bakPrefix . '* tables (' . implode(', ', $originalTables) . ') still hold the ' .
                'original data and must be recovered manually',
                'ERROR'
            );
            return false;
        }

        // Step 1: Drop all non-_bak_ tables (partially imported by the failed
        // restore) — frees the original names for the snapshot import below.
        // _bak_* is untouched.
        $tablesResult = ExecHelper::mysqlQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_type = 'BASE TABLE' AND table_name NOT LIKE '{$bakPrefix}%'",
            $creds
        );
        $importedTables = $tablesResult['success'] ? array_map(fn ($r) => $r[0], $tablesResult['rows']) : [];
        $this->log("[Restore/Rollback] Found " . count($importedTables) . " partially-imported table(s) to drop", 'DEBUG');

        if (!empty($importedTables)) {
            $drops = [];
            foreach ($importedTables as $table) {
                $drops[] = Identifier::quote($table);
            }
            $dropSQL = "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS " . implode(', ', $drops) . "; SET FOREIGN_KEY_CHECKS = 1;";

            $dropResult = ExecHelper::mysqlExec($dropSQL, $creds, $dbName);

            if (!$dropResult['success']) {
                // Non-fatal: the snapshot's own per-table DROP TABLE IF EXISTS below
                // will still clear these before recreating them.
                $this->log("[Restore/Rollback] Failed to drop partially-imported tables (snapshot import will retry via its own DROP IF EXISTS): " . $dropResult['error'], 'WARNING');
            }
        }

        // Step 2: Import the rollback snapshot directly into the (now freed)
        // original names, restoring ALL database objects atomically. _bak_* is
        // left completely alone here — it is the safety net if this import fails.
        $this->log("[Restore/Rollback] Importing rollback snapshot into original table names", 'DEBUG');
        $importResult = ExecHelper::mysqlImport($creds, $dbName, $snapshotPath);

        if (!$importResult['success']) {
            $this->log(
                "[Restore/Rollback] CRITICAL: Snapshot import failed: {$importResult['error']} — " .
                'the ' . $bakPrefix . '* tables (' . implode(', ', $originalTables) . ') still hold the ' .
                'original data and must be recovered manually; the live table names ' .
                'may currently be missing or partially populated',
                'ERROR'
            );
            return false;
        }

        // Step 3: Verify the tables the snapshot was supposed to restore
        // actually exist before trusting the import and discarding the
        // _bak_* safety net.
        $verifyResult = ExecHelper::mysqlQuery(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$dbName}' AND table_type = 'BASE TABLE'",
            $creds
        );
        $liveTables = $verifyResult['success'] ? array_map(fn ($r) => $r[0], $verifyResult['rows']) : [];
        $missingAfterRollback = array_values(array_diff($originalTables, $liveTables));

        if (!empty($missingAfterRollback)) {
            $this->log(
                '[Restore/Rollback] CRITICAL: snapshot import reported success but ' .
                count($missingAfterRollback) . ' table(s) are still missing (' . implode(', ', $missingAfterRollback) . ') — ' .
                'the ' . $bakPrefix . '* copies still hold the original data and must be recovered manually',
                'ERROR'
            );
            return false;
        }

        // Step 4: Snapshot fully restored and verified — the _bak_* safety net
        // is no longer needed.
        $bakTables = array_map(static fn (string $t): string => Identifier::quote($bakPrefix . $t), $originalTables);
        $bakDropSQL = "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS " . implode(', ', $bakTables) . "; SET FOREIGN_KEY_CHECKS = 1;";
        $bakDropResult = ExecHelper::mysqlExec($bakDropSQL, $creds, $dbName);
        if (!($bakDropResult['success'] ?? false)) {
            // Non-fatal: orphaned _bak_* tables are reclaimed by
            // cleanupOrphanedBakTables() on the next restore attempt.
            $this->log("[Restore/Rollback] Failed to drop {$bakPrefix}* safety-net tables (orphans left for next cleanup): " . ($bakDropResult['error'] ?? 'unknown'), 'WARNING');
        }

        unlink($snapshotPath);
        $this->log("[Restore/Rollback] Rollback snapshot imported and verified successfully — all database objects restored", 'DEBUG');
        $this->log('In-place restore rolled back successfully', 'INFO');
        return true;
    }

    /**
     * Restore files from a backup archive.
     *
     * Extracts files to a temporary directory, verifies integrity, takes a
     * full pre-restore snapshot of the live root, and rsync's the archive's
     * files onto the project root. If the destructive sync fails partway (or
     * any later step throws), automatically rolls back to the pre-restore
     * snapshot so an interrupted restore does not leave the site half-synced
     * and unreachable.
     *
     * The restore-maintenance flag and the backup/restore mutual-exclusion
     * lock are managed by the caller (the facade's restore orchestration),
     * spanning both this method and restoreDatabase() together — not here, so
     * neither is released/removed between the database and file restore phases.
     *
     * @param int $backupId Backup record ID
     * @return array{success: bool, error: ?string, rolled_back?: bool}
     */
    public function restoreFiles(int $backupId): array
    {
        $backup = $this->backupEngine->getBackup($backupId);
        if (!$backup) {
            return ['success' => false, 'error' => 'Backup not found'];
        }

        if ($backup->file_deleted_at !== null) {
            return ['success' => false, 'error' => 'Backup file has been deleted'];
        }

        $backupPath = $this->backupEngine->getBackupDir() . '/' . $backup->filename;
        if (!file_exists($backupPath)) {
            return ['success' => false, 'error' => 'Backup file not found on disk'];
        }

        $this->log("[Restore/Files] Starting file restore: backup_id={$backupId}, file={$backup->filename}", 'DEBUG');

        $this->initProgress(['verify_archive', 'extract_files', 'pre_restore_snapshot', 'restore_files', 'finalize']);
        $this->stepProgress('verify_archive');

        $integrity = $this->backupEngine->verifyArchiveIntegrity($backupPath);
        if (!$integrity['valid']) {
            $this->failProgress('verify_archive');
            $this->log("[Restore/Files] Archive integrity check failed: {$integrity['error']}", 'DEBUG');
            return ['success' => false, 'error' => 'Archive integrity check failed: ' . $integrity['error']];
        }

        if (!$integrity['has_files']) {
            $this->failProgress('verify_archive');
            $this->log("[Restore/Files] Archive does not contain files", 'DEBUG');
            return ['success' => false, 'error' => 'Archive does not contain files'];
        }

        $this->log("[Restore/Files] Archive integrity verified", 'DEBUG');

        // No PHP max_execution_time for the critical section below (snapshot +
        // sync + possible rollback can run long on a large install). This does
        // NOT defeat a web-server-level hard timeout (PHP-FPM
        // request_terminate_timeout, Apache Timeout/mod_proxy, nginx
        // fastcgi_read_timeout) — those can still kill the worker mid-restore
        // regardless of this call. The restore-maintenance flag limits the
        // blast radius to "site shows maintenance until an admin notices and
        // re-runs the standalone restore script."
        set_time_limit(0);

        $rootDir = $this->rootPath;
        $timestamp = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        $extractDir = $this->tempPath . '/restore_files_' . $timestamp;
        $preRestoreDir = $this->tempPath . '/pre_restore_' . $timestamp;
        // Shared across snapshot creation, the forward sync, and the rollback
        // sync below — MUST be identical every time. If the snapshot excluded a
        // path that a later rollback sync did not, rollback's `rsync --delete`
        // would delete that path from the live tree.
        $syncExcludes = Excludes::fileSync($this->rootPath, $this->backupEngine->getBackupDir(), $this->tempPath);
        $snapshotReady = false;

        try {
            // Step 1: Extract files from archive
            $this->stepProgress('extract_files');
            $this->log("[Restore/Files] Step 1: Extracting files from archive to {$extractDir}", 'DEBUG');
            if (!is_dir($extractDir)) {
                mkdir($extractDir, 0775, true);
            }

            $filesExtract = ExecHelper::tarExtract($backupPath, $extractDir, './files/*');
            if (!$filesExtract['success']) {
                $this->failProgress('extract_files');
                throw new \RuntimeException('Failed to extract files: ' . ($filesExtract['error'] ?? 'unknown'));
            }

            $filesDir = $extractDir . '/files';
            if (!is_dir($filesDir)) {
                $this->log("[Restore/Files] No files directory found in extracted archive", 'DEBUG');
                throw new \RuntimeException('No files directory found in extracted archive');
            }
            $this->log("[Restore/Files] Files extracted successfully", 'DEBUG');

            // Disk-space check BEFORE the destructive sync: the pre-restore
            // snapshot needs roughly the live root's size (minus the shared
            // excludes), on top of the archive's files/ tree just extracted onto
            // the same filesystem.
            $extractedSize = ExecHelper::directorySize($filesDir);
            $liveRootSize = ExecHelper::directorySize($rootDir, $syncExcludes);
            $spaceCheck = $this->ensureDiskSpace($extractedSize + $liveRootSize, 'file restore');
            if (!$spaceCheck['success']) {
                $this->failProgress('extract_files');
                throw new \RuntimeException($spaceCheck['error']);
            }

            // Step 2: Take a full pre-restore snapshot of the live root. A
            // snapshot of only the paths present in the backup would NOT capture
            // files `--delete` removes during the forward sync (anything live
            // but absent from the backup) — the snapshot must cover the FULL
            // tree (minus $syncExcludes) for the rollback below to be able to
            // fully undo the forward sync.
            $this->stepProgress('pre_restore_snapshot');
            $this->log("[Restore/Files] Step 2: Snapshotting current files to {$preRestoreDir}", 'DEBUG');
            if (!is_dir($preRestoreDir)) {
                mkdir($preRestoreDir, 0775, true);
            }
            $snapshotResult = ExecHelper::syncDirectories($rootDir, $preRestoreDir, $syncExcludes);
            if (!$snapshotResult['success']) {
                $this->failProgress('pre_restore_snapshot');
                throw new \RuntimeException('Pre-restore snapshot failed: ' . $snapshotResult['error']);
            }
            // Only from this point on is a rollback possible/safe — rolling back
            // from an incomplete or missing snapshot with `rsync --delete` would
            // itself wipe the live tree.
            $snapshotReady = true;
            $this->log("[Restore/Files] Pre-restore snapshot completed successfully", 'DEBUG');

            // Step 3: Rsync files onto the live root — the destructive step the
            // snapshot above protects against.
            $this->stepProgress('restore_files');
            $this->log("[Restore/Files] Step 3: Sync files to {$rootDir}", 'DEBUG');
            $syncResult = ExecHelper::syncDirectories($filesDir, $rootDir, $syncExcludes);

            if (!$syncResult['success']) {
                $this->failProgress('restore_files');
                $this->log("[Restore/Files] File sync failed: " . $syncResult['error'], 'ERROR');
                throw new \RuntimeException('File restore (sync) failed: ' . $syncResult['error']);
            }
            $this->log("[Restore/Files] Rsync completed successfully", 'DEBUG');

            // Step 4: Clear OPcache
            $this->stepProgress('finalize');
            $this->log("[Restore/Files] Step 4: Clearing OPcache", 'DEBUG');
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }

            // Cleanup — only once a consistent (successful) state is reached.
            Fs::removeDirectory($extractDir);
            Fs::removeDirectory($preRestoreDir);

            $this->completeProgress();
            $this->log("[Restore/Files] File restore completed successfully", 'DEBUG');
            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log('File restore failed: ' . $e->getMessage(), 'ERROR');

            // The extracted archive content is no longer needed either way.
            Fs::removeDirectory($extractDir);

            if ($snapshotReady) {
                return $this->rollbackFileRestore($rootDir, $preRestoreDir, $syncExcludes, $e->getMessage());
            }

            // Failed before the snapshot existed (verify/extract/disk-space
            // stage) — nothing was touched on the live tree yet, nothing to roll back.
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Roll back a failed file restore to the pre-restore snapshot taken in
     * {@see restoreFiles()}.
     *
     * Only ever called once the snapshot is confirmed complete. On rollback
     * success, the snapshot is removed (a consistent state has been reached
     * again). On rollback failure, the snapshot is deliberately preserved on
     * disk for manual recovery and a CRITICAL log entry is written.
     *
     * @param string $rootDir Absolute path to the project root
     * @param string $preRestoreDir Absolute path to the pre-restore snapshot
     * @param array $excludes Shared exclusion list (see Excludes::fileSync())
     * @param string $originalError The error that triggered the rollback
     * @return array{success: bool, error: string, rolled_back: bool}
     */
    private function rollbackFileRestore(string $rootDir, string $preRestoreDir, array $excludes, string $originalError): array
    {
        $this->log("[Restore/Files/Rollback] Attempting rollback from pre-restore snapshot: {$preRestoreDir}", 'WARNING');

        $rollbackResult = ExecHelper::syncDirectories($preRestoreDir, $rootDir, $excludes);

        if (!$rollbackResult['success']) {
            $this->log(
                "[Restore/Files/Rollback] CRITICAL: rollback FAILED — the site may be in an inconsistent, " .
                "unreachable state. Pre-restore snapshot preserved at {$preRestoreDir} for manual recovery. " .
                'Rollback error: ' . $rollbackResult['error'],
                'ERROR'
            );
            return [
                'success' => false,
                'rolled_back' => false,
                'error' => $originalError . ' — ' . $this->t->translate('TEXT_BACKUP_ROLLBACK_FAILED_MANUAL_RECOVERY', ['path' => $preRestoreDir]),
            ];
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        $this->log('[Restore/Files/Rollback] Rollback successful — previous file state restored', 'INFO');
        Fs::removeDirectory($preRestoreDir);

        return [
            'success' => false,
            'rolled_back' => true,
            'error' => $originalError . ' — ' . $this->t->translate('TEXT_BACKUP_ROLLBACK_SUCCEEDED'),
        ];
    }

    /**
     * @param string $message
     * @param string $level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        try {
            ($this->logger)($message, $level);
        } catch (\Throwable) {
            // A broken host logger must never break a backup/restore operation.
        }
    }
}
