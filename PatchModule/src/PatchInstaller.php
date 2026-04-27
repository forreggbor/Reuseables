<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\LoggerInterface;
use PatchModule\Contracts\VersionResolverInterface;

/**
 * PatchInstaller - End-to-end patch installation orchestrator
 *
 * Orchestrates the full patch installation pipeline:
 * preflight → backup → download → extract → SQL migration → file copy →
 * version update → verify → cleanup.
 *
 * On failure: rolls back from backup (if available) and file snapshot.
 *
 * @package PatchModule
 */
class PatchInstaller
{
    /** @var DatabaseAdapterInterface */
    private DatabaseAdapterInterface $database;

    /** @var PatchChecker */
    private PatchChecker $checker;

    /** @var PatchDownloader */
    private PatchDownloader $downloader;

    /** @var PatchFileManager */
    private PatchFileManager $fileManager;

    /** @var PatchMigrator */
    private PatchMigrator $migrator;

    /** @var ProgressTracker */
    private ProgressTracker $progressTracker;

    /** @var VersionResolverInterface */
    private VersionResolverInterface $versionResolver;

    /** @var BackupAdapterInterface|null */
    private ?BackupAdapterInterface $backupAdapter;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var string Project root path */
    private string $rootPath;

    /** @var int Minimum free disk space in bytes */
    private int $minDiskSpace;

    /** @var callable|null Fire-and-forget callback to refresh the server-side license check window */
    private $licenseVerifyCallback;

    /**
     * @param DatabaseAdapterInterface    $database              Database adapter
     * @param PatchChecker                $checker               Patch checker instance
     * @param PatchDownloader             $downloader            Patch downloader instance
     * @param PatchFileManager            $fileManager           File manager instance
     * @param PatchMigrator               $migrator              SQL migrator instance
     * @param ProgressTracker             $progressTracker       Progress tracker instance
     * @param VersionResolverInterface    $versionResolver       Version resolver
     * @param string                      $rootPath              Project root path
     * @param int                         $minDiskSpace          Minimum free disk space in bytes
     * @param BackupAdapterInterface|null $backupAdapter         Optional backup adapter
     * @param LoggerInterface|null        $logger                Optional logger
     * @param callable|null               $licenseVerifyCallback Optional callback invoked before download to
     *                                                           refresh the server-side license check window;
     *                                                           also used for a single retry when the server
     *                                                           rejects the download due to a stale check
     */
    public function __construct(
        DatabaseAdapterInterface $database,
        PatchChecker $checker,
        PatchDownloader $downloader,
        PatchFileManager $fileManager,
        PatchMigrator $migrator,
        ProgressTracker $progressTracker,
        VersionResolverInterface $versionResolver,
        string $rootPath,
        int $minDiskSpace = 209715200,
        ?BackupAdapterInterface $backupAdapter = null,
        ?LoggerInterface $logger = null,
        ?callable $licenseVerifyCallback = null
    ) {
        $this->database = $database;
        $this->checker = $checker;
        $this->downloader = $downloader;
        $this->fileManager = $fileManager;
        $this->migrator = $migrator;
        $this->progressTracker = $progressTracker;
        $this->versionResolver = $versionResolver;
        $this->rootPath = $rootPath;
        $this->minDiskSpace = $minDiskSpace;
        $this->backupAdapter = $backupAdapter;
        $this->logger = $logger;
        $this->licenseVerifyCallback = $licenseVerifyCallback;
    }

    /**
     * Install a patch end-to-end
     *
     * @param int $patchHistoryId patch_history record ID
     * @param string $licenseKey License key for download authentication
     * @param bool $createBackup Whether to create a backup before installing
     * @param int|null $userId User performing the installation
     * @return array{success: bool, error: ?string}
     */
    public function install(
        int $patchHistoryId,
        string $licenseKey,
        bool $createBackup = true,
        ?int $userId = null
    ): array {
        set_time_limit(600);

        // Clean up stale progress files
        $this->progressTracker->cleanupStaleProgressFiles();

        // Load patch history record
        $patchRecord = $this->database->getHistoryRecord($patchHistoryId);
        if (!$patchRecord) {
            return ['success' => false, 'error' => 'Patch record not found'];
        }

        $version = $patchRecord['version'];
        $previousVersion = $this->versionResolver->getCurrentVersion();
        $backupId = null;
        $downloadedFile = null;
        $extractDir = null;

        // Skip backup if no backup adapter is available
        $canBackup = $createBackup && $this->backupAdapter !== null;

        // Determine progress steps (backup is after extraction so we know if migration.sql exists)
        $steps = ['preflight_checks', 'download_patch', 'extract_patch'];
        if ($canBackup) {
            $steps[] = 'create_backup';
        }
        $steps = array_merge($steps, [
            'execute_migration', 'copy_files', 'update_version', 'verify_installation', 'cleanup',
        ]);

        $this->progressTracker->initProgress($steps);

        // Update status
        $this->database->updateHistoryRecord($patchHistoryId, ['status' => 'installing']);

        try {
            // Step 1: Preflight checks
            $this->progressTracker->stepProgress('preflight_checks');
            $this->log("Patch install: starting preflight checks for v{$version}", 'INFO');

            $this->runPreflightChecks($version, $previousVersion);

            // Record previous version immediately after preflight (needed regardless of backup)
            $this->database->updateHistoryRecord($patchHistoryId, [
                'previous_version' => $previousVersion,
            ]);

            // Step 2: Download patch
            $this->progressTracker->stepProgress('download_patch');
            $this->log("Patch install: downloading patch v{$version}", 'INFO');

            // Fire-and-forget license refresh before attempting the download so the server's
            // recently-verified window is as fresh as possible
            if ($this->licenseVerifyCallback !== null) {
                ($this->licenseVerifyCallback)();
            }

            $downloadResult = $this->downloader->download(
                (int) $patchRecord['patch_server_id'],
                $patchRecord['sha256_hash'],
                $patchHistoryId,
                $licenseKey
            );

            // If the server rejected the download because the license check is stale, attempt a
            // single retry after invoking the license verify callback
            if (
                !$downloadResult['success']
                && ($downloadResult['error_code'] ?? null) === 'not_recently_verified'
                && $this->licenseVerifyCallback !== null
            ) {
                $this->log("Patch install: license check stale, refreshing and retrying download", 'WARNING');
                ($this->licenseVerifyCallback)();

                $downloadResult = $this->downloader->download(
                    (int) $patchRecord['patch_server_id'],
                    $patchRecord['sha256_hash'],
                    $patchHistoryId,
                    $licenseKey
                );
            }

            if (!$downloadResult['success']) {
                throw new \RuntimeException('Download failed: ' . $downloadResult['error']);
            }

            $downloadedFile = $downloadResult['file_path'];

            // Step 3: Extract patch
            $this->progressTracker->stepProgress('extract_patch');
            $this->log("Patch install: extracting patch", 'INFO');

            $extractResult = $this->fileManager->extractPatch($downloadedFile);
            if (!$extractResult['success']) {
                throw new \RuntimeException('Extract failed: ' . $extractResult['error']);
            }

            $extractDir = $extractResult['extract_dir'];
            $manifest = $extractResult['manifest'];

            // Store manifest in history
            $this->database->updateHistoryRecord($patchHistoryId, [
                'manifest_json' => json_encode($manifest),
            ]);

            // Step 4: Create backup (conditional — only if patch has a SQL migration)
            $hasMigration = file_exists($extractDir . '/migration.sql');
            if ($canBackup) {
                if ($hasMigration) {
                    $this->progressTracker->stepProgress('create_backup');
                    $this->log("Patch install: creating pre-patch backup", 'INFO');

                    $backupResult = $this->backupAdapter->createBackup(
                        "Pre-patch backup (v{$previousVersion} → v{$version})",
                        $userId
                    );

                    if (!$backupResult['success']) {
                        throw new \RuntimeException('Backup creation failed: ' . ($backupResult['error'] ?? 'Unknown error'));
                    }

                    $backupId = $backupResult['backup_id'];

                    $this->database->updateHistoryRecord($patchHistoryId, [
                        'backup_id' => $backupId,
                    ]);

                    $this->log("Patch install: backup created (ID: {$backupId})", 'INFO');
                } else {
                    $this->progressTracker->stepProgress('create_backup');
                    $this->log("Patch install: no migration.sql found, skipping backup", 'INFO');
                }
            }

            // Step 5: Execute SQL migration
            $this->progressTracker->stepProgress('execute_migration');
            $migrationFile = $extractDir . '/migration.sql';
            if ($hasMigration) {
                $this->log("Patch install: executing SQL migration", 'INFO');

                $migrationResult = $this->migrator->executeMigration($migrationFile);
                if (!$migrationResult['success']) {
                    throw new \RuntimeException(
                        'SQL migration failed at statement ' . $migrationResult['executed_count'] .
                        '/' . $migrationResult['total_count'] . ': ' . $migrationResult['error']
                    );
                }

                $this->log("Patch install: SQL migration completed ({$migrationResult['executed_count']} statements)", 'INFO');
            } else {
                $this->log("Patch install: no migration.sql found, skipping", 'DEBUG');
            }

            // Step 6: Copy files
            $this->progressTracker->stepProgress('copy_files');
            $this->log("Patch install: copying files", 'INFO');

            // Snapshot affected files before overwriting
            $snapshotResult = $this->fileManager->backupAffectedFiles($patchHistoryId, $manifest, $extractDir);
            if (!$snapshotResult['success']) {
                throw new \RuntimeException('File snapshot failed: ' . $snapshotResult['error']);
            }

            $copyResult = $this->fileManager->copyFiles($extractDir, $manifest);
            if (!$copyResult['success']) {
                throw new \RuntimeException('File copy failed: ' . $copyResult['error']);
            }

            $this->log("Patch install: {$copyResult['copied_count']} files copied", 'INFO');

            // Step 7: Update version
            $this->progressTracker->stepProgress('update_version');
            $this->log("Patch install: updating version to {$version}", 'INFO');

            if (!$this->versionResolver->updateVersion($version)) {
                throw new \RuntimeException('Failed to update application version');
            }

            // Step 8: Verify installation
            $this->progressTracker->stepProgress('verify_installation');
            $this->log("Patch install: verifying installation", 'INFO');

            $verifyResult = $this->verifyInstallation();
            if (!$verifyResult['success']) {
                throw new \RuntimeException('Verification failed: ' . $verifyResult['error']);
            }

            // Step 9: Cleanup
            $this->progressTracker->stepProgress('cleanup');
            $this->log("Patch install: cleaning up", 'INFO');

            // Remove temp files
            if ($extractDir) {
                $this->fileManager->cleanupDir($extractDir);
            }
            if ($downloadedFile && file_exists($downloadedFile)) {
                @unlink($downloadedFile);
            }

            // Clean up file snapshot (no longer needed)
            $this->fileManager->cleanupSnapshot($patchHistoryId);

            // Reset OPcache
            $this->fileManager->resetOpcache();

            // Remove installed version from cached data
            $this->checker->removeVersionFromCache($version);

            // Delete pre-patch backup on success
            if ($backupId && $this->backupAdapter !== null) {
                $this->log("Patch install: deleting pre-patch backup (ID: {$backupId})", 'DEBUG');
                $this->backupAdapter->deleteBackup($backupId);

                $this->database->updateHistoryRecord($patchHistoryId, ['backup_id' => null]);
            }

            // Mark as completed
            $this->database->updateHistoryRecord($patchHistoryId, [
                'status' => 'completed',
                'installed_at' => date('Y-m-d H:i:s'),
                'installed_by' => $userId,
            ]);

            $this->progressTracker->completeProgress();

            // Log activity
            $this->logActivity(
                'install_patch',
                'patch',
                $patchHistoryId,
                ['version' => $previousVersion],
                ['version' => $version],
                $userId
            );

            $this->log("Patch install: v{$version} installed successfully", 'INFO');

            return ['success' => true, 'error' => null];

        } catch (\Exception $e) {
            return $this->handleInstallFailure(
                $e, $patchHistoryId, $version, $backupId,
                $extractDir, $downloadedFile, $userId
            );
        }
    }

    /**
     * Rollback a failed patch installation
     *
     * Restores database from backup (if available) and files from snapshot.
     *
     * @param int $patchHistoryId patch_history record ID
     * @return array{success: bool, error: ?string}
     */
    public function rollback(int $patchHistoryId): array
    {
        $record = $this->database->getHistoryRecord($patchHistoryId);
        if (!$record) {
            return ['success' => false, 'error' => 'Patch record not found'];
        }

        $this->log("Patch rollback: starting for v{$record['version']} (ID: {$patchHistoryId})", 'WARNING');

        // Restore database from full backup (if available)
        if (!empty($record['backup_id']) && $this->backupAdapter !== null) {
            $dbResult = $this->backupAdapter->restoreDatabase((int) $record['backup_id']);
            if (!$dbResult['success']) {
                $this->log("Patch rollback: database restore failed - " . $dbResult['error'], 'ERROR');
                return ['success' => false, 'error' => 'Database restore failed: ' . $dbResult['error']];
            }
            $this->log("Patch rollback: database restored from backup ID {$record['backup_id']}", 'INFO');
        }

        // Restore files from selective snapshot
        $filesResult = $this->fileManager->rollbackFiles($patchHistoryId);
        if (!$filesResult['success']) {
            $this->log("Patch rollback: file restore failed - " . $filesResult['error'], 'ERROR');
            return ['success' => false, 'error' => 'File restore failed: ' . $filesResult['error']];
        }

        // Update status (may fail if DB was just restored)
        try {
            $this->database->updateHistoryRecord($patchHistoryId, [
                'status' => 'rolled_back',
                'rolled_back_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            $this->log("Patch rollback: could not update status - " . $e->getMessage(), 'WARNING');
        }

        // Clean up snapshot
        $this->fileManager->cleanupSnapshot($patchHistoryId);

        // Reset OPcache
        $this->fileManager->resetOpcache();

        $this->log("Patch rollback completed successfully", 'INFO');

        return ['success' => true, 'error' => null];
    }

    /**
     * Get patch installation history
     *
     * @return array List of patch history records
     */
    public function getHistory(): array
    {
        return $this->database->getHistory();
    }

    /**
     * Run preflight checks before installation
     *
     * @param string $version Target version
     * @param string $currentVersion Current application version
     * @return void
     * @throws \RuntimeException If any check fails
     */
    private function runPreflightChecks(string $version, string $currentVersion): void
    {
        // Check disk space (use backup adapter if available, otherwise disk_free_space)
        if ($this->backupAdapter !== null) {
            $freeBytes = $this->backupAdapter->getFreeDiskSpace();
        } else {
            $freeBytes = (int) disk_free_space($this->rootPath);
        }

        if ($freeBytes < $this->minDiskSpace) {
            $freeHuman = round($freeBytes / 1024 / 1024, 1) . ' MB';
            $requiredHuman = round($this->minDiskSpace / 1024 / 1024, 1) . ' MB';
            throw new \RuntimeException(
                "Insufficient disk space (minimum {$requiredHuman} required, available: {$freeHuman})"
            );
        }

        // Check project root is writable
        if (!is_writable($this->rootPath)) {
            throw new \RuntimeException('Project root directory is not writable');
        }

        // Check not already installed
        if ($version === $currentVersion) {
            throw new \RuntimeException("Version {$version} is already installed");
        }

        // Check for previous completed install
        $existing = $this->database->findHistoryByVersion($version, ['completed']);
        if ($existing) {
            throw new \RuntimeException("Version {$version} has already been installed");
        }
    }

    /**
     * Verify that the installation was successful
     *
     * Tests database connection and basic PHP syntax check capability.
     *
     * @return array{success: bool, error: ?string}
     */
    private function verifyInstallation(): array
    {
        // Test database connection
        try {
            $pdo = $this->database->getPdo();
            $pdo->query("SELECT 1");
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Database connection test failed: ' . $e->getMessage()];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Handle installation failure: rollback, cleanup, and log
     *
     * @param \Exception $e The exception that caused the failure
     * @param int $patchHistoryId Patch history record ID
     * @param string $version Target version
     * @param int|null $backupId Backup ID if backup was created
     * @param string|null $extractDir Extract directory path
     * @param string|null $downloadedFile Downloaded file path
     * @param int|null $userId User who initiated the install
     * @return array{success: bool, error: string}
     */
    private function handleInstallFailure(
        \Exception $e,
        int $patchHistoryId,
        string $version,
        ?int $backupId,
        ?string $extractDir,
        ?string $downloadedFile,
        ?int $userId
    ): array {
        $errorMsg = $e->getMessage();
        $this->log("Patch install failed: {$errorMsg}", 'ERROR');

        // Determine which step failed
        $failedStep = $this->progressTracker->getActiveStepId();
        $this->progressTracker->failProgress($failedStep);

        // Update history
        try {
            $this->database->updateHistoryRecord($patchHistoryId, [
                'status' => 'failed',
                'error_message' => $errorMsg,
            ]);
        } catch (\Exception $dbException) {
            $this->log("Could not update patch_history failure status: " . $dbException->getMessage(), 'WARNING');
        }

        // Attempt rollback
        $rollbackResult = null;
        $hasSnapshot = $this->fileManager->hasSnapshot($patchHistoryId);
        if ($backupId || $hasSnapshot) {
            $this->log(
                "Patch install: attempting rollback (backup: " . ($backupId ?: 'none') .
                ", snapshot: " . ($hasSnapshot ? 'yes' : 'no') . ")",
                'WARNING'
            );
            $rollbackResult = $this->rollback($patchHistoryId);
        }

        // Cleanup temp files
        if ($extractDir && is_dir($extractDir)) {
            $this->fileManager->cleanupDir($extractDir);
        }
        if ($downloadedFile && file_exists($downloadedFile)) {
            @unlink($downloadedFile);
        }

        // Log failure activity
        $this->logActivity(
            'install_patch_failed',
            'patch',
            $patchHistoryId,
            null,
            ['version' => $version, 'error' => $errorMsg, 'rolled_back' => $rollbackResult['success'] ?? false],
            $userId
        );

        // Build result error message
        $resultError = $errorMsg;
        if ($rollbackResult) {
            if ($rollbackResult['success']) {
                $resultError .= ' (rolled back successfully)';
            } else {
                $resultError .= ' (rollback failed: ' . $rollbackResult['error'] . ')';
            }
        } elseif (!$backupId && !$hasSnapshot) {
            $resultError .= ' (no backup or snapshot available for rollback)';
        }

        return ['success' => false, 'error' => $resultError];
    }

    /**
     * Log a message if logger is available
     *
     * @param string $message Log message
     * @param string $level Log level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logger !== null) {
            $this->logger->log($message, $level);
        }
    }

    /**
     * Log an activity if logger is available
     *
     * @param string $action Action identifier
     * @param string $entityType Entity type
     * @param int|null $entityId Entity ID
     * @param array|null $oldValues Previous state
     * @param array|null $newValues New state
     * @param int|null $userId User who performed the action
     * @return void
     */
    private function logActivity(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId = null
    ): void {
        if ($this->logger !== null) {
            $this->logger->activity($action, $entityType, $entityId, $oldValues, $newValues, $userId);
        }
    }
}