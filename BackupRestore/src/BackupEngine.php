<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * BackupEngine — backup creation, listing, deletion, integrity, disk-space,
 * and download-token management. Restore is handled by RestoreEngine.
 */

namespace BackupRestore;

use ActivityLogs\ActivityLogger;
use BackupRestore\Contracts\TokenStoreInterface;
use BackupRestore\Contracts\TranslatorInterface;
use BackupRestore\Exec\ExecHelper;
use PDO;

/**
 * Core service for backup creation, listing, deletion, and integrity checks.
 * Handles database dumps (mysqldump), file archives (tar), and TGZ packaging.
 * Uses temporary MySQL option files (via Exec\ShellHelper) for secure
 * credential handling. Restore operations live in {@see RestoreEngine}.
 *
 * @package BackupRestore
 */
final class BackupEngine
{
    /** @var Translator */
    private readonly Translator $t;

    /**
     * @param PDO $pdo Bookkeeping connection (backups/backup_profiles/backup_remote_servers tables)
     * @param array{host:string,port:?int,database:string,username:string,password:string} $dbCredentials Target-DB credentials for mysqldump
     * @param string $rootPath Absolute project root (file-archive base)
     * @param string $backupDir Absolute directory backup archives are stored in
     * @param string $tempPath Absolute scratch/temp directory
     * @param string $appVersion
     * @param string $appEnv
     * @param array{app_name:string,manual_retention_days:int} $settings
     * @param array{backups:string,backup_profiles:string,backup_remote_servers:string} $tableNames
     * @param callable(string,string):void $logger
     * @param callable():?int $getCurrentUserId
     * @param callable(int[]):array<int,string> $getUserMap
     * @param TranslatorInterface|null $translator
     * @param TokenStoreInterface $tokenStore
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $dbCredentials,
        private readonly string $rootPath,
        private readonly string $backupDir,
        private readonly string $tempPath,
        private readonly string $appVersion,
        private readonly string $appEnv,
        private readonly array $settings,
        private readonly array $tableNames,
        private $logger,
        private $getCurrentUserId,
        private $getUserMap,
        ?TranslatorInterface $translator,
        private readonly TokenStoreInterface $tokenStore,
    ) {
        $this->t = new Translator($translator);
    }

    /**
     * Hard headroom required in addition to the estimated need, on top of any
     * disk-space check performed before creating a backup.
     */
    private const int MIN_FREE_BYTES_FOR_BACKUP = 100 * 1024 * 1024; // 100MB

    // =========================================================================
    // Paths & locking
    // =========================================================================

    /**
     * Ensure backup directory exists with proper security.
     *
     * Creates the configured backup directory with .htaccess protection
     * if it does not already exist.
     *
     * @return void
     */
    public function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }

        $htaccess = $this->backupDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
    }

    /** @return string Absolute path to the backup-archive storage directory */
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    /** @return string Absolute path to the scratch/temp directory */
    public function getTempDir(): string
    {
        return $this->tempPath;
    }

    /** @return string Absolute path to the project root */
    public function getRootDir(): string
    {
        return $this->rootPath;
    }

    /** @return array{host:string,port:?int,database:string,username:string,password:string} */
    public function getDbCredentials(): array
    {
        return $this->dbCredentials;
    }

    /**
     * Run a callback while holding the exclusive backup/restore mutual-exclusion lock.
     *
     * @param callable $callback
     * @return array{success: bool, error?: string, lock_failed?: bool}
     */
    public function withLock(callable $callback): array
    {
        return Lock::withLock($this->tempPath, $callback, $this->logger, $this->t);
    }

    /** @return array<int,string> Always-excluded directories (backup/restore internals) */
    public function getAlwaysExcluded(): array
    {
        return Excludes::always($this->rootPath, $this->backupDir);
    }

    /** @return array<int,string> Exclusion list shared by all restore-time file-sync operations */
    public function getFileSyncExcludes(): array
    {
        return Excludes::fileSync($this->rootPath, $this->backupDir, $this->tempPath);
    }

    /** @return string Absolute path of the restore-maintenance flag file */
    public function getRestoreMaintenanceFlagPath(): string
    {
        return Excludes::restoreMaintenanceFlagPath($this->tempPath);
    }

    /** @return array<int,string> Default-excluded directories a host may override per profile */
    public function getDefaultExcluded(): array
    {
        return Excludes::defaultExcluded();
    }

    // =========================================================================
    // Backup creation
    // =========================================================================

    /**
     * Create a full database dump using mysqldump.
     *
     * @param string $outputPath Destination path for SQL dump file
     * @param array|null $credentials Optional DB credentials (uses configured target-DB creds if null)
     * @param array|null $tables Restrict dump to these table names; null = all tables
     * @return array{success: bool, tables_count: int, error: string|null}
     */
    public function createDatabaseDump(string $outputPath, ?array $credentials = null, ?array $tables = null): array
    {
        $label = $tables !== null ? 'partial (' . count($tables) . ' tables)' : 'full';
        $this->log("[Backup/DB] Creating database dump ({$label}): {$outputPath}", 'DEBUG');
        $creds = $credentials ?? $this->dbCredentials;

        $dumpResult = ExecHelper::mysqldump($creds, $outputPath, true, true, true, $tables);

        if (!$dumpResult['success']) {
            $this->log("[Backup/DB] mysqldump failed: " . $dumpResult['error'], 'DEBUG');
            return ['success' => false, 'tables_count' => 0, 'error' => $dumpResult['error']];
        }

        $this->log("[Backup/DB] Database dump completed: {$dumpResult['tables_count']} tables", 'DEBUG');
        return ['success' => true, 'tables_count' => $dumpResult['tables_count'], 'error' => null];
    }

    /**
     * Create a file archive using tar.
     *
     * Packs selected project directories into a tar file, respecting
     * included/excluded paths.
     *
     * @param string $outputPath Destination path for tar file
     * @param array|null $includedPaths Paths to include (null = all project dirs)
     * @param array|null $excludedPaths Additional paths to exclude
     * @return array{success: bool, error: string|null}
     */
    public function createFileArchive(string $outputPath, ?array $includedPaths = null, ?array $excludedPaths = null): array
    {
        $this->log("[Backup/Files] Creating file archive: {$outputPath}", 'DEBUG');

        $allExcluded = $this->getAlwaysExcluded();
        if (!empty($excludedPaths)) {
            $allExcluded = array_merge($allExcluded, $excludedPaths);
        }

        $tarResult = ExecHelper::tarCreate($outputPath, $this->rootPath, $allExcluded, $includedPaths);

        if (!$tarResult['success']) {
            $this->log("[Backup/Files] tar failed: " . $tarResult['error'], 'DEBUG');
            return ['success' => false, 'error' => $tarResult['error']];
        }

        $this->log("[Backup/Files] File archive created successfully", 'DEBUG');
        return ['success' => true, 'error' => null];
    }

    /**
     * Build manifest.json content for a backup archive.
     *
     * @param array $meta Metadata to include in the manifest
     * @return array Complete manifest data structure
     */
    public function buildManifest(array $meta): array
    {
        $creds = $this->dbCredentials;

        return [
            'version' => 1,
            'app_name' => $this->settings['app_name'] ?? '',
            'app_version' => $this->appVersion !== '' ? $this->appVersion : 'unknown',
            'backup_date' => date('c'),
            'backup_type' => $meta['type'] ?? 'full',
            'created_by' => $meta['created_by_name'] ?? 'system',
            'created_by_id' => $meta['created_by_id'] ?? null,
            'environment' => $this->appEnv !== '' ? $this->appEnv : 'production',
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->getMysqlVersion(),
            'restore_token' => $meta['restore_token'] ?? bin2hex(random_bytes(32)),
            'database' => [
                'name' => $creds['database'],
                'charset' => 'utf8mb4',
                'tables_count' => $meta['tables_count'] ?? 0,
                'dump_file' => 'database/' . $creds['database'] . '.sql',
                'dump_checksum_sha256' => $meta['db_checksum'] ?? null,
                'partial' => !empty($meta['partial_tables']),
                'partial_tables' => $meta['partial_tables'] ?? null,
            ],
            'files' => [
                'included_paths' => $meta['included_paths'] ?? null,
                'excluded_paths' => $meta['excluded_paths'] ?? null,
                'total_files' => $meta['total_files'] ?? 0,
            ],
            'checksum_sha256' => null, // Set after archive creation
            'note' => $meta['note'] ?? null,
        ];
    }

    /**
     * Create a complete backup (TGZ archive).
     *
     * Orchestrates database dump, file archive, manifest creation, and TGZ
     * packaging into a single backup file, under the exclusive backup/restore
     * lock (see {@see withLock()}).
     *
     * @param array $options {
     *   type: 'full'|'database'|'files',
     *   note: ?string,
     *   included_paths: ?array,
     *   excluded_paths: ?array,
     *   tables: ?array     — limit the DB dump to these table names (partial backup),
     *   profile_id: ?int,
     *   created_by: ?int
     * }
     * @return array{success: bool, backup_id: ?int, filename: ?string, restore_token: ?string, error: ?string}
     */
    public function createBackup(array $options = []): array
    {
        return $this->withLock(fn (): array => $this->createBackupLocked($options));
    }

    /**
     * Actual backup-creation implementation, run while {@see withLock()} holds
     * the exclusive backup/restore lock. Not called directly — use {@see createBackup()}.
     *
     * @param array $options See {@see createBackup()}.
     * @return array{success: bool, backup_id: ?int, filename: ?string, restore_token: ?string, error: ?string}
     */
    private function createBackupLocked(array $options): array
    {
        $type = $options['type'] ?? 'full';
        $note = $options['note'] ?? null;
        $includedPaths = $options['included_paths'] ?? null;
        $excludedPaths = $options['excluded_paths'] ?? null;
        $partialTables = $options['tables'] ?? null;
        $profileId = $options['profile_id'] ?? null;
        $createdBy = $options['created_by'] ?? ($this->getCurrentUserId)();

        $this->log("[Backup] Starting backup creation: type={$type}, profile_id={$profileId}, created_by={$createdBy}", 'DEBUG');

        $this->ensureBackupDirectory();

        $diskInfo = $this->getDiskSpaceInfo();
        if ($diskInfo['stat_failed']) {
            // Could not determine free space at all (e.g. open_basedir) —
            // don't block every backup on a misleading "insufficient disk
            // space" message; proceed and let mysqldump/tar fail naturally
            // (with a real disk-full error) if space is genuinely short.
            $this->log('[Backup] Disk-space pre-flight check skipped: could not determine free space for ' . $this->backupDir, 'WARNING');
        } elseif ($diskInfo['free_bytes'] < self::MIN_FREE_BYTES_FOR_BACKUP) {
            return ['success' => false, 'backup_id' => null, 'filename' => null, 'restore_token' => null, 'error' => 'Insufficient disk space (minimum 100MB required)'];
        }

        set_time_limit(300);

        $timestamp = date('Ymd_His');
        // A random suffix is required, not cosmetic: two backups started in the
        // same wall-clock second (concurrent admins, or a cron-scheduled backup
        // landing on the same second as an on-demand one) would otherwise both
        // resolve to the identical filename, and the second write would silently
        // overwrite the first backup's archive on disk while its DB row still
        // reports success — a real collision, reproduced via the harness.
        $filename = 'backup_app_' . $timestamp . '_' . bin2hex(random_bytes(4)) . '.tgz';
        $restoreToken = bin2hex(random_bytes(32));

        // Cryptographically-unique workdir suffix (not just the second-granular
        // timestamp used in $filename) — belt-and-suspenders against a workdir
        // collision if this method is ever invoked without going through the
        // withLock() single-flight guarantee above.
        $workDirSuffix = $timestamp . '_' . bin2hex(random_bytes(6));

        $backupsTable = $this->tableNames['backups'];
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$backupsTable} (profile_id, filename, type, status, note, included_paths, excluded_paths, restore_token, created_by, created_at)
             VALUES (:profile_id, :filename, :type, 'in_progress', :note, :included_paths, :excluded_paths, :restore_token, :created_by, NOW())"
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':filename' => $filename,
            ':type' => $type,
            ':note' => $note,
            ':included_paths' => $includedPaths ? json_encode($includedPaths) : null,
            ':excluded_paths' => $excludedPaths ? json_encode($excludedPaths) : null,
            ':restore_token' => $restoreToken,
            ':created_by' => $createdBy,
        ]);
        $backupId = (int) $this->pdo->lastInsertId();

        $workDir = $this->tempPath . '/backup_' . $workDirSuffix;
        if (!is_dir($workDir)) {
            mkdir($workDir, 0775, true);
        }
        mkdir($workDir . '/database', 0775, true);

        try {
            $tablesCount = 0;
            $dbChecksum = null;
            $totalFiles = 0;

            // Step 1: Database dump — logged only when this step actually runs, so a
            // files-only backup's log doesn't claim a database dump is happening.
            if ($type === 'full' || $type === 'database') {
                $this->log("[Backup] Step 1: Database dump (type={$type})", 'DEBUG');
                $dbDumpPath = $workDir . '/database/' . $this->dbCredentials['database'] . '.sql';

                $dumpResult = $this->createDatabaseDump($dbDumpPath, null, $partialTables);
                if (!$dumpResult['success']) {
                    throw new \RuntimeException('Database dump failed: ' . $dumpResult['error']);
                }

                $tablesCount = $dumpResult['tables_count'];
                $dbChecksum = hash_file('sha256', $dbDumpPath);
                $this->log("[Backup] Database dump completed: {$tablesCount} tables, checksum={$dbChecksum}", 'DEBUG');
            }

            // Step 2: File archive — same reasoning: only logged when it actually runs.
            if ($type === 'full' || $type === 'files') {
                $this->log("[Backup] Step 2: File archive", 'DEBUG');
                $filesArchivePath = $workDir . '/files.tar';

                $archiveResult = $this->createFileArchive($filesArchivePath, $includedPaths, $excludedPaths);
                if (!$archiveResult['success']) {
                    throw new \RuntimeException('File archive failed: ' . $archiveResult['error']);
                }

                $totalFiles = ExecHelper::tarCount($filesArchivePath);
                $this->log("[Backup] File archive completed: {$totalFiles} files", 'DEBUG');
            }

            // Step 3: Create manifest
            $this->log("[Backup] Step 3: Creating manifest", 'DEBUG');
            $creatorName = 'system';
            if ($createdBy) {
                $map = ($this->getUserMap)([$createdBy]);
                $creatorName = $map[$createdBy] ?? ('user #' . $createdBy);
            }

            $manifest = $this->buildManifest([
                'type' => $type,
                'restore_token' => $restoreToken,
                'created_by_name' => $creatorName,
                'created_by_id' => $createdBy,
                'tables_count' => $tablesCount,
                'db_checksum' => $dbChecksum,
                'included_paths' => $includedPaths,
                'excluded_paths' => $excludedPaths,
                'total_files' => $totalFiles,
                'note' => $note,
                'partial_tables' => $partialTables,
            ]);

            file_put_contents($workDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Step 4: Create final TGZ
            $this->log("[Backup] Step 4: Creating TGZ archive", 'DEBUG');
            $backupPath = $this->backupDir . '/' . $filename;

            // If we have a files.tar, extract it into the workdir/files/ and remove the tar
            if (isset($filesArchivePath) && file_exists($filesArchivePath)) {
                mkdir($workDir . '/files', 0775, true);
                ExecHelper::tarExtractUncompressed($filesArchivePath, $workDir . '/files');
                unlink($filesArchivePath);
            }

            $tgzResult = ExecHelper::tarCreateGz($backupPath, $workDir);

            if (!$tgzResult['success']) {
                throw new \RuntimeException('TGZ creation failed: ' . $tgzResult['error']);
            }

            // Step 5: Calculate checksum and file size
            $fileSize = filesize($backupPath);
            $checksum = hash_file('sha256', $backupPath);
            $this->log("[Backup] Step 5: TGZ created - size=" . number_format($fileSize) . " bytes, checksum={$checksum}", 'DEBUG');

            // Step 6: Update backup record
            $updateStmt = $this->pdo->prepare(
                "UPDATE {$backupsTable} SET status = 'completed', size_bytes = :size, tables_count = :tables,
                 checksum_sha256 = :checksum WHERE id = :id"
            );
            $updateStmt->execute([
                ':size' => $fileSize,
                ':tables' => $tablesCount,
                ':checksum' => $checksum,
                ':id' => $backupId,
            ]);

            Fs::removeDirectory($workDir);

            $this->log("[Backup] Backup completed successfully: id={$backupId}, filename={$filename}", 'DEBUG');

            $this->audit('create_' . $type . '_backup', (string) $backupId, null, [
                'filename' => $filename,
                'profile_id' => $profileId,
                'size_bytes' => $fileSize,
            ], $createdBy);

            return [
                'success' => true,
                'backup_id' => $backupId,
                'filename' => $filename,
                'restore_token' => $restoreToken,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            // Best-effort bookkeeping update — a secondary failure here (e.g.
            // the DB connection itself is what failed) must not replace the
            // real error below with an uncaught exception; the caller still
            // needs the ['success' => false, ...] array back.
            try {
                $failStmt = $this->pdo->prepare(
                    "UPDATE {$backupsTable} SET status = 'failed', error_message = :error WHERE id = :id"
                );
                $failStmt->execute([
                    ':error' => $e->getMessage(),
                    ':id' => $backupId,
                ]);
            } catch (\Throwable $failStmtError) {
                $this->log('Could not record failed-backup status: ' . $failStmtError->getMessage(), 'WARNING');
            }

            Fs::removeDirectory($workDir);
            $backupPath = $this->backupDir . '/' . $filename;
            if (file_exists($backupPath)) {
                unlink($backupPath);
            }

            $this->log('Backup creation failed: ' . $e->getMessage(), 'ERROR');

            $this->audit('create_' . $type . '_backup_failed', (string) $backupId, null, [
                'error' => $e->getMessage(),
            ], $createdBy);

            return [
                'success' => false,
                'backup_id' => $backupId,
                'filename' => null,
                'restore_token' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Listing, retrieval, deletion
    // =========================================================================

    /**
     * List all backups from the database.
     *
     * Creator names are resolved via the injected user-map callable (no
     * `users` JOIN — this module never queries the host's user table
     * directly).
     *
     * @param array $filters Optional filters (type, status, etc.)
     * @return array<int,object> List of backup records, each with an
     *         additional `creator_name` property
     */
    public function listBackups(array $filters = []): array
    {
        $backupsTable = $this->tableNames['backups'];
        $profilesTable = $this->tableNames['backup_profiles'];
        $remoteTable = $this->tableNames['backup_remote_servers'];

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'b.type = :type';
            $params[':type'] = $filters['type'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'b.status = :status';
            $params[':status'] = $filters['status'];
        }

        $sql = "SELECT b.*, bp.name AS profile_name, brs.name AS remote_server_name
                FROM {$backupsTable} b
                LEFT JOIN {$profilesTable} bp ON b.profile_id = bp.id
                LEFT JOIN {$remoteTable} brs ON b.remote_server_id = brs.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY b.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        return $this->attachCreatorNames($rows);
    }

    /**
     * Get a single backup record by ID.
     *
     * @param int $id Backup ID
     * @return object|null Backup record (with `creator_name` attached), or null if not found
     */
    public function getBackup(int $id): ?object
    {
        $backupsTable = $this->tableNames['backups'];
        $profilesTable = $this->tableNames['backup_profiles'];
        $remoteTable = $this->tableNames['backup_remote_servers'];

        $stmt = $this->pdo->prepare(
            "SELECT b.*, bp.name AS profile_name, brs.name AS remote_server_name
             FROM {$backupsTable} b
             LEFT JOIN {$profilesTable} bp ON b.profile_id = bp.id
             LEFT JOIN {$remoteTable} brs ON b.remote_server_id = brs.id
             WHERE b.id = :id"
        );
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch(PDO::FETCH_OBJ);
        if (!$result) {
            return null;
        }

        $enriched = $this->attachCreatorNames([$result]);
        return $enriched[0];
    }

    /**
     * Flip a backup row from 'in_progress' to 'completed' if it is still
     * marked in_progress. Used by {@see RestoreEngine::restoreDatabase()}
     * after a successful restore: the archive's own bookkeeping row was
     * captured by mysqldump while the *original* backup-creation run was
     * still in_progress, so a straight restore of that row would otherwise
     * leave it permanently stuck in that state once swapped back in.
     *
     * Failure here is intentionally non-fatal to the caller — a restore that
     * otherwise succeeded should not be reported as failed merely because
     * this cosmetic status fixup could not run (e.g. the bookkeeping
     * connection was mid-swap).
     *
     * @param int $backupId
     * @return void
     */
    public function markBackupCompletedIfInProgress(int $backupId): void
    {
        $backupsTable = $this->tableNames['backups'];
        $this->pdo
            ->prepare("UPDATE {$backupsTable} SET status = 'completed' WHERE id = :id AND status = 'in_progress'")
            ->execute([':id' => $backupId]);
    }

    /**
     * Batch-resolve and attach a `creator_name` property to each row, via the
     * injected user-map callable. Rows with a null `created_by` get 'system';
     * an id the resolver doesn't know about falls back to "user #<id>" so a
     * broken/incomplete resolver never breaks the backup list.
     *
     * @param array<int,object> $rows Rows with a `created_by` property
     * @return array<int,object>
     */
    private function attachCreatorNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row->created_by)) {
                $ids[(int) $row->created_by] = true;
            }
        }

        $map = [];
        if ($ids !== []) {
            try {
                $map = ($this->getUserMap)(array_keys($ids));
            } catch (\Throwable $e) {
                // A broken/throwing host resolver must never break the backup
                // list — fall back to id-based names below (see docblock).
                $this->log('get_user_map callable failed: ' . $e->getMessage(), 'WARNING');
            }
        }

        foreach ($rows as $row) {
            if (empty($row->created_by)) {
                $row->creator_name = 'system';
                continue;
            }
            $uid = (int) $row->created_by;
            $row->creator_name = $map[$uid] ?? ('user #' . $uid);
        }

        return $rows;
    }

    /**
     * Delete only the backup file (keep history record).
     *
     * Sets file_deleted_at timestamp but retains the history row.
     *
     * @param int $id Backup ID
     * @return array{success: bool, error: ?string}
     */
    public function deleteBackupFile(int $id): array
    {
        $backup = $this->getBackup($id);
        if (!$backup) {
            return ['success' => false, 'error' => 'Backup not found'];
        }

        if ($backup->file_deleted_at !== null) {
            return ['success' => false, 'error' => 'Backup file already deleted'];
        }

        $filePath = $this->backupDir . '/' . $backup->filename;

        // Validate path is within backup directory. Only a real, on-disk path
        // can be containment-checked; if it doesn't exist there's nothing to
        // unlink and no escape to worry about — file_exists() below correctly
        // no-ops and this call just marks the row deleted (e.g. the file was
        // already removed out-of-band). A resolvable path is rejected unless
        // it's under $realBackupDir WITH a trailing separator boundary — a
        // bare prefix compare would let "/backups_evil" match "/backups".
        $realPath = realpath($filePath);
        if ($realPath !== false) {
            $realBackupDir = realpath($this->backupDir);
            if ($realBackupDir === false || !str_starts_with($realPath, rtrim($realBackupDir, '/') . '/')) {
                return ['success' => false, 'error' => 'Invalid file path'];
            }
        }

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $backupsTable = $this->tableNames['backups'];
        $stmt = $this->pdo->prepare("UPDATE {$backupsTable} SET file_deleted_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return ['success' => true, 'error' => null];
    }

    /**
     * Delete backup file AND history record completely.
     *
     * @param int $id Backup ID
     * @return array{success: bool, error: ?string}
     */
    public function deleteBackupFull(int $id): array
    {
        $backup = $this->getBackup($id);
        if (!$backup) {
            return ['success' => false, 'error' => 'Backup not found'];
        }

        if ($backup->file_deleted_at === null) {
            $filePath = $this->backupDir . '/' . $backup->filename;

            // Same containment guard as deleteBackup() — unlike that method,
            // this one previously had none at all.
            $realPath = realpath($filePath);
            if ($realPath !== false) {
                $realBackupDir = realpath($this->backupDir);
                if ($realBackupDir === false || !str_starts_with($realPath, rtrim($realBackupDir, '/') . '/')) {
                    return ['success' => false, 'error' => 'Invalid file path'];
                }
            }

            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $backupsTable = $this->tableNames['backups'];
        $stmt = $this->pdo->prepare("DELETE FROM {$backupsTable} WHERE id = :id");
        $stmt->execute([':id' => $id]);

        return ['success' => true, 'error' => null];
    }

    // =========================================================================
    // Integrity, disk space, statistics
    // =========================================================================

    /**
     * Verify integrity of a backup archive.
     *
     * Tests if the TGZ file is valid and contains expected structure.
     *
     * @param string $filePath Path to TGZ file
     * @return array{valid: bool, has_manifest: bool, has_database: bool, has_files: bool, error: ?string}
     */
    public function verifyArchiveIntegrity(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'has_manifest' => false, 'has_database' => false, 'has_files' => false, 'error' => 'File not found'];
        }

        if (!ExecHelper::gzipTest($filePath)) {
            return ['valid' => false, 'has_manifest' => false, 'has_database' => false, 'has_files' => false, 'error' => 'Archive is corrupted'];
        }

        $listResult = ExecHelper::tarList($filePath);

        if (!$listResult['success']) {
            return ['valid' => false, 'has_manifest' => false, 'has_database' => false, 'has_files' => false, 'error' => 'Cannot read archive contents'];
        }

        $contents = implode("\n", $listResult['files']);
        $hasManifest = str_contains($contents, 'manifest.json');
        $hasDatabase = str_contains($contents, 'database/');
        $hasFiles = str_contains($contents, 'files/');

        return [
            'valid' => true,
            'has_manifest' => $hasManifest,
            'has_database' => $hasDatabase,
            'has_files' => $hasFiles,
            'error' => null,
        ];
    }

    /**
     * Get disk space information for the backup directory.
     *
     * `disk_free_space()`/`disk_total_space()` can return `false` in
     * restricted environments (e.g. open_basedir) — `stat_failed` lets a
     * caller distinguish "genuinely low space" from "could not determine
     * space" rather than silently treating an unreadable filesystem stat as
     * zero free bytes.
     *
     * @return array{free_bytes: int, total_bytes: int, used_bytes: int, free_human: string, total_human: string, used_human: string, usage_percent: float, stat_failed: bool}
     */
    public function getDiskSpaceInfo(): array
    {
        $this->ensureBackupDirectory();

        $freeRaw = disk_free_space($this->backupDir);
        $totalRaw = disk_total_space($this->backupDir);
        $statFailed = $freeRaw === false || $totalRaw === false;

        $free = $statFailed ? 0 : (int) $freeRaw;
        $total = $statFailed ? 0 : (int) $totalRaw;
        $used = $total - $free;

        if ($statFailed) {
            $this->log("[Backup/DiskSpace] Could not determine free/total disk space for {$this->backupDir} (disk_free_space/disk_total_space returned false)", 'WARNING');
        }

        return [
            'free_bytes' => $free,
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_human' => FileSize::format($free),
            'total_human' => FileSize::format($total),
            'used_human' => FileSize::format($used),
            'usage_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
            'stat_failed' => $statFailed,
        ];
    }

    /**
     * Get total size of all backup files in storage.
     *
     * @return array{total_bytes: int, total_human: string, count: int}
     */
    public function getBackupStorageInfo(): array
    {
        $totalSize = 0;
        $count = 0;

        if (is_dir($this->backupDir)) {
            $files = glob($this->backupDir . '/*.tgz');
            foreach ($files as $file) {
                $totalSize += filesize($file);
                $count++;
            }
        }

        return [
            'total_bytes' => $totalSize,
            'total_human' => FileSize::format($totalSize),
            'count' => $count,
        ];
    }

    /**
     * Build hierarchical directory tree for folder selection UI.
     *
     * Returns a nested structure of project directories suitable for
     * rendering as a tree view with checkboxes.
     *
     * @param string|null $basePath Base path to scan (null = project root)
     * @param string|null $relativeTo Relative path for lazy-loading subdirectories
     * @return array Nested directory structure
     */
    public function getDirectoryTree(?string $basePath = null, ?string $relativeTo = null): array
    {
        $rootDir = $this->rootPath;
        $scanPath = $basePath ?? $rootDir;

        if ($relativeTo) {
            $scanPath = $rootDir . '/' . ltrim($relativeTo, '/');
        }

        // Validate path is within project root
        // A bare prefix compare would let a sibling directory like
        // "{$rootDir}_evil" match $rootDir — require either exact equality
        // or a trailing separator boundary.
        $realScan = realpath($scanPath);
        $realRoot = realpath($rootDir);
        if (!$realScan || !$realRoot || (!str_starts_with($realScan, rtrim($realRoot, '/') . '/') && $realScan !== $realRoot)) {
            return [];
        }

        $entries = scandir($scanPath);
        if ($entries === false) {
            $this->log("[Backup/Tree] Could not read directory: {$scanPath}", 'WARNING');
            return [];
        }

        $items = [];
        $alwaysExcluded = $this->getAlwaysExcluded();
        $defaultExcluded = $this->getDefaultExcluded();

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $scanPath . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            // Skip hidden directories (except .env which is a file)
            if (str_starts_with($entry, '.') && $entry !== '.env') {
                continue;
            }

            $relativePath = ltrim(str_replace($rootDir, '', $fullPath), '/');

            $isLocked = false;
            foreach ($alwaysExcluded as $excluded) {
                if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                    $isLocked = true;
                    break;
                }
            }

            $isDefaultExcluded = false;
            foreach ($defaultExcluded as $excluded) {
                if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                    $isDefaultExcluded = true;
                    break;
                }
            }

            $hasChildren = false;
            $subEntries = @scandir($fullPath);
            if ($subEntries) {
                foreach ($subEntries as $sub) {
                    if ($sub !== '.' && $sub !== '..' && is_dir($fullPath . '/' . $sub) && !str_starts_with($sub, '.')) {
                        $hasChildren = true;
                        break;
                    }
                }
            }

            $items[] = [
                'name' => $entry,
                'path' => $relativePath,
                'has_children' => $hasChildren,
                'is_locked' => $isLocked,
                'is_default_excluded' => $isDefaultExcluded,
                'checked' => !$isLocked && !$isDefaultExcluded,
            ];
        }

        usort($items, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * Get MySQL server version.
     *
     * @return string MySQL version string
     */
    public function getMysqlVersion(): string
    {
        try {
            $stmt = $this->pdo->query("SELECT VERSION()");
            return $stmt->fetchColumn() ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Get backup statistics for dashboard.
     *
     * @return array{total: int, completed: int, failed: int, latest: ?object, storage: array}
     */
    public function getStats(): array
    {
        $backupsTable = $this->tableNames['backups'];

        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$backupsTable}")->fetchColumn();
        $completed = (int) $this->pdo->query("SELECT COUNT(*) FROM {$backupsTable} WHERE status = 'completed'")->fetchColumn();
        $failed = (int) $this->pdo->query("SELECT COUNT(*) FROM {$backupsTable} WHERE status = 'failed'")->fetchColumn();

        $latestStmt = $this->pdo->query("SELECT * FROM {$backupsTable} WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1");
        $latest = $latestStmt->fetch(PDO::FETCH_OBJ) ?: null;
        if ($latest) {
            $latest = $this->attachCreatorNames([$latest])[0];
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'latest' => $latest,
            'storage' => $this->getBackupStorageInfo(),
        ];
    }

    // =========================================================================
    // Download tokens
    // =========================================================================

    /** Download-token lifetime in seconds. */
    private const int DOWNLOAD_TOKEN_TTL_SECONDS = 300;

    /**
     * Generate a secure, single-use download token for a backup file.
     *
     * @param int $backupId Backup ID to generate a token for
     * @return string Download token
     */
    public function generateDownloadToken(int $backupId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokenStore->put($token, ['backup_id' => $backupId], self::DOWNLOAD_TOKEN_TTL_SECONDS);
        return $token;
    }

    /**
     * Validate and consume a download token.
     *
     * @param string $token Download token to validate
     * @return int|false Backup ID if valid, false if expired/invalid/already consumed
     */
    public function validateDownloadToken(string $token): int|false
    {
        $payload = $this->tokenStore->take($token);
        return $payload['backup_id'] ?? false;
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    /**
     * Clean up stale temp working directories and orphaned rollback snapshots.
     *
     * @param int $maxAgeHours Delete entries older than this many hours
     * @return int Number of entries removed
     */
    public function cleanupTempFiles(int $maxAgeHours = 24): int
    {
        $tempDir = $this->tempPath;
        $count = 0;

        if (!is_dir($tempDir)) {
            return 0;
        }

        $entries = scandir($tempDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $tempDir . '/' . $entry;
            $age = time() - filemtime($path);
            if ($age <= $maxAgeHours * 3600) {
                continue;
            }

            if (is_dir($path)) {
                // Only clean backup/restore related temp dirs
                if (!str_starts_with($entry, 'backup_') && !str_starts_with($entry, 'restore_') && !str_starts_with($entry, 'pre_restore_')) {
                    continue;
                }
                Fs::removeDirectory($path);
                $count++;
            } elseif (str_starts_with($entry, 'rollback_snapshot_') && str_ends_with($entry, '.sql')) {
                $this->log("[Backup/Cleanup] Removing orphaned rollback snapshot: {$path} (age " . round($age / 3600, 1) . "h)", 'WARNING');
                unlink($path);
                $count++;
            }
        }

        return $count;
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

    /**
     * Write an audit entry via the sibling ActivityLogs\ActivityLogger module
     * (direct lib-to-lib dependency, not a contract — see BackupRestore.php).
     * Never lets a logging failure affect the caller: ActivityLogger::log()
     * itself never throws, and a missing ActivityLogger class (host has not
     * made it autoloadable) is silently skipped.
     *
     * @param string $action e.g. 'create_full_backup', 'create_full_backup_failed'
     * @param string|int|null $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $userId
     * @return void
     */
    private function audit(string $action, string|int|null $entityId, ?array $oldValues, ?array $newValues, ?int $userId): void
    {
        if (!class_exists(ActivityLogger::class)) {
            return;
        }

        ActivityLogger::log($userId, $action, 'backup', $entityId, $oldValues, $newValues, $userId === null ? 'system' : 'admin');
    }
}
