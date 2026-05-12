<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PatchInstaller - End-to-end patch installation orchestrator
 */

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
 * file removal → version update → verify → cleanup.
 *
 * On failure: rolls back from backup (if available) and file snapshot.
 * All server error codes are propagated through the result array so callers
 * can show targeted UI messages without parsing error strings.
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

    /** @var MaintenanceMode|null Maintenance mode toggle (engaged during install/rollback) */
    private ?MaintenanceMode $maintenanceMode;

    /** @var string[] Absolute paths to compiled-cache directories cleared after file mutations */
    private array $cachePathsToClear;

    /** @var int Number of successful installs whose snapshot/backup is kept for rollback */
    private int $keepLastSnapshots;

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
     * @param MaintenanceMode|null        $maintenanceMode       Optional maintenance mode handler engaged during install
     * @param string[]                    $cachePathsToClear     Absolute paths to compiled-cache dirs cleared after
     *                                                           file mutations (e.g. Twig template cache)
     * @param int                         $keepLastSnapshots     Number of successful installs whose rollback
     *                                                           artifacts are retained (default: 3)
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
        ?callable $licenseVerifyCallback = null,
        ?MaintenanceMode $maintenanceMode = null,
        array $cachePathsToClear = [],
        int $keepLastSnapshots = 3
    ) {
        $this->database              = $database;
        $this->checker               = $checker;
        $this->downloader            = $downloader;
        $this->fileManager           = $fileManager;
        $this->migrator              = $migrator;
        $this->progressTracker       = $progressTracker;
        $this->versionResolver       = $versionResolver;
        $this->rootPath              = $rootPath;
        $this->minDiskSpace          = $minDiskSpace;
        $this->backupAdapter         = $backupAdapter;
        $this->logger                = $logger;
        $this->licenseVerifyCallback = $licenseVerifyCallback;
        $this->maintenanceMode       = $maintenanceMode;
        $this->cachePathsToClear     = $cachePathsToClear;
        $this->keepLastSnapshots     = max(1, $keepLastSnapshots);
    }

    /**
     * Install a patch end-to-end
     *
     * @param int      $patchHistoryId patch_history record ID
     * @param string   $licenseKey     License key for download authentication
     * @param bool     $createBackup   Whether to create a backup before installing
     * @param int|null $userId         User performing the installation
     * @return array{success: bool, error: ?string, error_code: ?string, retry_after: ?int}
     */
    public function install(
        int $patchHistoryId,
        string $licenseKey,
        bool $createBackup = true,
        ?int $userId = null,
        ?string $language = null
    ): array {
        set_time_limit(0);
        ignore_user_abort(true);

        $this->progressTracker->cleanupStaleProgressFiles();

        $patchRecord = $this->database->getHistoryRecord($patchHistoryId);
        if (!$patchRecord) {
            return ['success' => false, 'error' => 'Patch record not found', 'error_code' => null, 'retry_after' => null];
        }

        $canBackup = $createBackup && $this->backupAdapter !== null;

        $steps = ['preflight_checks', 'download_patch', 'extract_patch'];
        if ($canBackup) {
            $steps[] = 'create_backup';
        }
        $steps = array_merge($steps, [
            'execute_migration', 'copy_files', 'remove_files', 'update_version', 'verify_installation', 'cleanup',
        ]);

        $this->progressTracker->initProgress($steps);

        $this->database->updateHistoryRecord($patchHistoryId, ['status' => 'installing']);

        if ($this->maintenanceMode !== null) {
            $this->maintenanceMode->enable($patchRecord['version'], $language);
        }

        $ctx = [
            'patchHistoryId'    => $patchHistoryId,
            'version'           => $patchRecord['version'],
            'previousVersion'   => $this->versionResolver->getCurrentVersion(),
            'canBackup'         => $canBackup,
            'userId'            => $userId,
            'extractDir'        => null,
            'manifest'          => null,
            'hasMigration'      => false,
            'backupId'          => null,
            'downloadedFile'    => null,
            'installErrorCode'  => null,
            'installRetryAfter' => null,
        ];

        try {
            // Step 1: Preflight checks
            $this->progressTracker->stepProgress('preflight_checks');
            $this->log("Patch install: starting preflight checks for v{$ctx['version']}", 'INFO');

            $this->fileManager->sweepStaleTmpFiles();

            $this->runPreflightChecks($ctx['version'], $ctx['previousVersion']);

            $this->database->updateHistoryRecord($patchHistoryId, [
                'previous_version' => $ctx['previousVersion'],
            ]);

            // Step 2: Download patch
            $this->progressTracker->stepProgress('download_patch');
            $this->log("Patch install: downloading patch v{$ctx['version']}", 'INFO');

            // Fire-and-forget license refresh before attempting the download
            if ($this->licenseVerifyCallback !== null) {
                ($this->licenseVerifyCallback)();
            }

            $downloadResult = $this->downloader->download(
                (int) $patchRecord['patch_server_id'],
                $patchRecord['sha256_hash'],
                $patchHistoryId,
                $licenseKey
            );

            // Single retry on stale license check after invoking the verify callback
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
                $ctx['installErrorCode']  = $downloadResult['error_code'] ?? null;
                $ctx['installRetryAfter'] = $downloadResult['retry_after'] ?? null;
                throw new \RuntimeException('Download failed: ' . $downloadResult['error']);
            }

            $ctx['downloadedFile'] = $downloadResult['file_path'];

            // Steps 3–10: delegated to private step helpers
            $this->extractStep($ctx, $ctx['downloadedFile']);

            if ($canBackup) {
                $this->backupStep($ctx);
            }

            $this->migrateStep($ctx);
            $this->copyStep($ctx);
            $this->removeStep($ctx);
            $this->updateVersionStep($ctx);
            $this->verifyStep($ctx);
            $this->cleanupStep($ctx);

            return ['success' => true, 'error' => null, 'error_code' => null, 'retry_after' => null];

        } catch (\Exception $e) {
            return $this->handleInstallFailure(
                $e, $patchHistoryId, $ctx['version'], $ctx['backupId'],
                $ctx['extractDir'], $ctx['downloadedFile'], $userId,
                $ctx['installErrorCode'], $ctx['installRetryAfter']
            );
        } finally {
            if ($this->maintenanceMode !== null) {
                try {
                    $this->maintenanceMode->disable();
                } catch (\Throwable $me) {
                    $this->log("Patch install: failed to disable maintenance mode: " . $me->getMessage(), 'ERROR');
                }
            }
        }
    }

    /**
     * Install a patch from a locally staged archive
     *
     * Installs a patch from a manually uploaded (local) archive file, skipping the
     * download step and license verification callback. Used for manual patch uploads
     * when the patch server is unavailable or patches are staged locally.
     *
     * Defence check ensures this method is only called for manually uploaded patches
     * (those with null patch_server_id).
     *
     * @param int      $patchHistoryId Patch history record ID
     * @param string   $archivePath    Absolute path to the locally staged archive
     * @param int|null $userId         User performing the installation
     * @param string|null $language    Optional language for maintenance mode messages
     * @return array{success: bool, error: ?string, error_code: ?string, retry_after: ?int}
     */
    public function installFromLocalArchive(
        int $patchHistoryId,
        string $archivePath,
        ?int $userId = null,
        ?string $language = null
    ): array {
        set_time_limit(0);
        ignore_user_abort(true);

        $this->progressTracker->cleanupStaleProgressFiles();

        $patchRecord = $this->database->getHistoryRecord($patchHistoryId);
        if (!$patchRecord) {
            return ['success' => false, 'error' => 'Patch record not found', 'error_code' => null, 'retry_after' => null];
        }

        // Defence: this method is only for manually uploaded patches
        if ($patchRecord['patch_server_id'] !== null) {
            return ['success' => false, 'error' => 'Not a manually uploaded patch', 'error_code' => null, 'retry_after' => null];
        }

        $canBackup = $this->backupAdapter !== null;

        $steps = ['preflight_checks', 'extract_patch'];
        if ($canBackup) {
            $steps[] = 'create_backup';
        }
        $steps = array_merge($steps, [
            'execute_migration', 'copy_files', 'remove_files', 'update_version', 'verify_installation', 'cleanup',
        ]);

        $this->progressTracker->initProgress($steps);
        $this->database->updateHistoryRecord($patchHistoryId, ['status' => 'installing']);

        if ($this->maintenanceMode !== null) {
            $this->maintenanceMode->enable($patchRecord['version'], $language);
        }

        $ctx = [
            'patchHistoryId'    => $patchHistoryId,
            'version'           => $patchRecord['version'],
            'previousVersion'   => $this->versionResolver->getCurrentVersion(),
            'canBackup'         => $canBackup,
            'userId'            => $userId,
            'extractDir'        => null,
            'manifest'          => null,
            'hasMigration'      => false,
            'backupId'          => null,
            'downloadedFile'    => $archivePath,   // deleted by cleanupStep
            'installErrorCode'  => null,
            'installRetryAfter' => null,
        ];

        try {
            // Step 1: Preflight checks
            $this->progressTracker->stepProgress('preflight_checks');
            $this->log("Patch install (manual): starting preflight checks for v{$ctx['version']}", 'INFO');

            $this->fileManager->sweepStaleTmpFiles();
            $this->runPreflightChecks($ctx['version'], $ctx['previousVersion']);
            $this->database->updateHistoryRecord($patchHistoryId, [
                'previous_version' => $ctx['previousVersion'],
            ]);

            // Steps 2–9: extract, backup, migrate, copy, remove, update version, verify, cleanup
            $this->extractStep($ctx, $archivePath);

            if ($canBackup) {
                $this->backupStep($ctx);
            }

            $this->migrateStep($ctx);
            $this->copyStep($ctx);
            $this->removeStep($ctx);
            $this->updateVersionStep($ctx);
            $this->verifyStep($ctx);
            $this->cleanupStep($ctx);

            // Invalidate the remote patch cache so the banner refreshes on next load
            $this->checker->invalidateCache();

            return ['success' => true, 'error' => null, 'error_code' => null, 'retry_after' => null];

        } catch (\Exception $e) {
            return $this->handleInstallFailure(
                $e, $patchHistoryId, $ctx['version'], $ctx['backupId'],
                $ctx['extractDir'], $ctx['downloadedFile'], $userId,
                $ctx['installErrorCode'], $ctx['installRetryAfter']
            );
        } finally {
            if ($this->maintenanceMode !== null) {
                try {
                    $this->maintenanceMode->disable();
                } catch (\Throwable $me) {
                    $this->log("Patch install (manual): failed to disable maintenance mode: " . $me->getMessage(), 'ERROR');
                }
            }
        }
    }

    /**
     * Extract the downloaded patch archive
     *
     * Calls the file manager to unpack the archive, records the extracted
     * directory, manifest and migration flag in $ctx, and persists the
     * manifest JSON to the patch history record.
     *
     * @param array  &$ctx        Installation context (mutated)
     * @param string $archivePath Absolute path to the downloaded archive
     * @return void
     * @throws \RuntimeException If extraction fails
     */
    private function extractStep(array &$ctx, string $archivePath): void
    {
        $this->progressTracker->stepProgress('extract_patch');
        $this->log("Patch install: extracting patch", 'INFO');

        $extractResult = $this->fileManager->extractPatch($archivePath);
        if (!$extractResult['success']) {
            $ctx['installErrorCode'] = $extractResult['error_code'] ?? null;
            throw new \RuntimeException('Extract failed: ' . $extractResult['error']);
        }

        $ctx['extractDir']   = $extractResult['extract_dir'];
        $ctx['manifest']     = $extractResult['manifest'];
        $ctx['hasMigration'] = file_exists($ctx['extractDir'] . '/migration.sql');

        $this->database->updateHistoryRecord($ctx['patchHistoryId'], [
            'manifest_json' => json_encode($ctx['manifest']),
        ]);
    }

    /**
     * Create a pre-patch database backup when a SQL migration is present
     *
     * If there is no migration, the step is still ticked for progress tracking
     * and a skip message is logged. Only called when canBackup is true.
     *
     * @param array &$ctx Installation context (mutated: backupId set on success)
     * @return void
     * @throws \RuntimeException If backup creation fails
     */
    private function backupStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('create_backup');

        if ($ctx['hasMigration']) {
            $this->log("Patch install: creating pre-patch backup", 'INFO');

            $backupResult = $this->backupAdapter->createBackup(
                "Pre-patch backup (v{$ctx['previousVersion']} → v{$ctx['version']})",
                $ctx['userId']
            );

            if (!$backupResult['success']) {
                throw new \RuntimeException('Backup creation failed: ' . ($backupResult['error'] ?? 'Unknown error'));
            }

            $ctx['backupId'] = $backupResult['backup_id'];

            $this->database->updateHistoryRecord($ctx['patchHistoryId'], [
                'backup_id' => $ctx['backupId'],
            ]);

            $this->log("Patch install: backup created (ID: {$ctx['backupId']})", 'INFO');
        } else {
            $this->log("Patch install: no migration.sql found, skipping backup", 'INFO');
        }
    }

    /**
     * Execute the SQL migration if one is present in the extracted patch
     *
     * Skips silently (at DEBUG level) when no migration.sql exists.
     *
     * @param array &$ctx Installation context
     * @return void
     * @throws \RuntimeException If the migration execution fails
     */
    private function migrateStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('execute_migration');

        if ($ctx['hasMigration']) {
            $this->log("Patch install: executing SQL migration", 'INFO');

            $migrationFile   = $ctx['extractDir'] . '/migration.sql';
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
    }

    /**
     * Snapshot affected files and copy new files from the extracted patch
     *
     * Takes a pre-install snapshot of all files that will be overwritten,
     * then copies the patched files into the project root.
     *
     * @param array &$ctx Installation context (mutated: installErrorCode set on failure)
     * @return void
     * @throws \RuntimeException If the snapshot or copy step fails
     */
    private function copyStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('copy_files');
        $this->log("Patch install: copying files", 'INFO');

        $snapshotResult = $this->fileManager->backupAffectedFiles($ctx['patchHistoryId'], $ctx['manifest'], $ctx['extractDir']);
        if (!$snapshotResult['success']) {
            $ctx['installErrorCode'] = $snapshotResult['error_code'] ?? null;
            throw new \RuntimeException('File snapshot failed: ' . $snapshotResult['error']);
        }

        $copyResult = $this->fileManager->copyFiles($ctx['extractDir'], $ctx['manifest']);
        if (!$copyResult['success']) {
            $ctx['installErrorCode'] = $copyResult['error_code'] ?? null;
            throw new \RuntimeException('File copy failed: ' . $copyResult['error']);
        }

        $this->log("Patch install: {$copyResult['copied_count']} files copied", 'INFO');
    }

    /**
     * Remove obsolete files listed in the patch manifest and clear compiled caches
     *
     * After removal, clears any configured compiled-template cache directories
     * (e.g. Twig) so that updated files take effect immediately.
     *
     * @param array &$ctx Installation context (mutated: installErrorCode set on failure)
     * @return void
     * @throws \RuntimeException If file removal fails
     */
    private function removeStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('remove_files');

        $removeResult = $this->fileManager->removeFiles($ctx['manifest']);
        if (!$removeResult['success']) {
            $ctx['installErrorCode'] = $removeResult['error_code'] ?? null;
            throw new \RuntimeException('File removal failed: ' . $removeResult['error']);
        }

        if ($removeResult['removed_count'] > 0) {
            $this->log("Patch install: {$removeResult['removed_count']} obsolete files removed", 'INFO');
        }

        // Clear compiled-template caches (e.g. Twig) so updated files take effect immediately
        if (!empty($this->cachePathsToClear)) {
            $this->fileManager->clearCachePaths($this->cachePathsToClear);
        }
    }

    /**
     * Persist the new application version to storage
     *
     * @param array &$ctx Installation context
     * @return void
     * @throws \RuntimeException If the version update fails
     */
    private function updateVersionStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('update_version');
        $this->log("Patch install: updating version to {$ctx['version']}", 'INFO');

        if (!$this->versionResolver->updateVersion($ctx['version'])) {
            throw new \RuntimeException('Failed to update application version');
        }
    }

    /**
     * Verify that the installation succeeded
     *
     * Delegates to verifyInstallation() and maps a failure to the
     * VERIFICATION_FAILED error code so the caller can surface targeted feedback.
     *
     * @param array &$ctx Installation context (mutated: installErrorCode set on failure)
     * @return void
     * @throws \RuntimeException If verification fails
     */
    private function verifyStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('verify_installation');
        $this->log("Patch install: verifying installation", 'INFO');

        $verifyResult = $this->verifyInstallation($ctx['manifest'], $ctx['version'], $ctx['extractDir']);
        if (!$verifyResult['success']) {
            $ctx['installErrorCode'] = ErrorCode::VERIFICATION_FAILED;
            throw new \RuntimeException('Verification failed: ' . $verifyResult['error']);
        }
    }

    /**
     * Clean up temporary files, reset caches, mark the install as completed and log success
     *
     * Removes the extracted patch directory and the downloaded archive, resets
     * OPcache, invalidates the version cache, records the completed status in the
     * database, prunes old rollback artifacts, completes the progress tracker and
     * logs the activity.
     *
     * @param array &$ctx Installation context
     * @return void
     */
    private function cleanupStep(array &$ctx): void
    {
        $this->progressTracker->stepProgress('cleanup');
        $this->log("Patch install: cleaning up", 'INFO');

        if ($ctx['extractDir']) {
            $this->fileManager->cleanupDir($ctx['extractDir']);
        }
        if ($ctx['downloadedFile'] && file_exists($ctx['downloadedFile'])) {
            @unlink($ctx['downloadedFile']);
        }

        $this->fileManager->resetOpcache();
        $this->checker->removeVersionFromCache($ctx['version']);

        $this->database->updateHistoryRecord($ctx['patchHistoryId'], [
            'status'       => 'completed',
            'installed_at' => date('Y-m-d H:i:s'),
            'installed_by' => $ctx['userId'],
        ]);

        // Prune rollback artifacts from older completed installs (keep the last N)
        $this->pruneOldRollbackArtifacts($ctx['patchHistoryId']);

        $this->progressTracker->completeProgress();

        $this->logActivity(
            'install_patch',
            'patch',
            $ctx['patchHistoryId'],
            ['version' => $ctx['previousVersion']],
            ['version' => $ctx['version']],
            $ctx['userId']
        );

        $this->log("Patch install: v{$ctx['version']} installed successfully", 'INFO');
    }

    /**
     * Rollback a failed patch installation
     *
     * Restores database from backup (if available) and files from snapshot.
     *
     * @param int      $patchHistoryId patch_history record ID
     * @param int|null $userId         User who triggered the rollback (null for internal/automated rollbacks)
     * @return array{success: bool, error: ?string}
     */
    public function rollback(int $patchHistoryId, ?int $userId = null): array
    {
        $record = $this->database->getHistoryRecord($patchHistoryId);
        if (!$record) {
            return ['success' => false, 'error' => 'Patch record not found'];
        }

        $this->log("Patch rollback: starting for v{$record['version']} (ID: {$patchHistoryId})", 'WARNING');

        if ($this->maintenanceMode !== null) {
            $this->maintenanceMode->enable($record['version'] ?? '');
        }

        try {
            return $this->doRollback($record, $patchHistoryId, $userId);
        } finally {
            if ($this->maintenanceMode !== null) {
                try {
                    $this->maintenanceMode->disable();
                } catch (\Throwable $me) {
                    $this->log("Patch rollback: failed to disable maintenance mode: " . $me->getMessage(), 'ERROR');
                }
            }
        }
    }

    /**
     * Execute rollback logic (called from rollback() within the maintenance-mode try/finally)
     *
     * @param array    $record          patch_history record
     * @param int      $patchHistoryId  patch_history record ID
     * @param int|null $userId          User who triggered the rollback; null for automated rollbacks
     * @return array{success: bool, error: ?string}
     */
    private function doRollback(array $record, int $patchHistoryId, ?int $userId = null): array
    {
        if (!empty($record['backup_id']) && $this->backupAdapter !== null) {
            $dbResult = $this->backupAdapter->restoreDatabase((int) $record['backup_id']);
            if (!$dbResult['success']) {
                $this->log("Patch rollback: database restore failed - " . $dbResult['error'], 'ERROR');
                $this->logActivity(
                    'rollback_patch_failed',
                    'patch',
                    $patchHistoryId,
                    null,
                    ['version' => $record['version'] ?? null, 'error' => 'Database restore failed: ' . ($dbResult['error'] ?? 'unknown')],
                    $userId
                );
                return ['success' => false, 'error' => 'Database restore failed: ' . $dbResult['error']];
            }
            $this->log("Patch rollback: database restored from backup ID {$record['backup_id']}", 'INFO');
        }

        $filesResult = $this->fileManager->rollbackFiles($patchHistoryId);
        if (!$filesResult['success']) {
            $this->log("Patch rollback: file restore failed - " . $filesResult['error'], 'ERROR');
            $this->logActivity(
                'rollback_patch_failed',
                'patch',
                $patchHistoryId,
                null,
                ['version' => $record['version'] ?? null, 'error' => 'File restore failed: ' . ($filesResult['error'] ?? 'unknown')],
                $userId
            );
            return ['success' => false, 'error' => 'File restore failed: ' . $filesResult['error']];
        }

        try {
            $this->database->updateHistoryRecord($patchHistoryId, [
                'status'          => 'rolled_back',
                'rolled_back_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            $this->log("Patch rollback: could not update status - " . $e->getMessage(), 'WARNING');
        }

        if (!empty($this->cachePathsToClear)) {
            $this->fileManager->clearCachePaths($this->cachePathsToClear);
        }

        $this->fileManager->cleanupSnapshot($patchHistoryId);
        $this->fileManager->resetOpcache();

        $this->log("Patch rollback completed successfully", 'INFO');

        $this->logActivity(
            'rollback_patch',
            'patch',
            $patchHistoryId,
            null,
            ['version' => $record['version'] ?? null],
            $userId
        );

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
     * @param string $version        Target version
     * @param string $currentVersion Current application version
     * @return void
     * @throws \RuntimeException If any check fails
     */
    private function runPreflightChecks(string $version, string $currentVersion): void
    {
        if ($this->backupAdapter !== null) {
            $freeBytes = $this->backupAdapter->getFreeDiskSpace();
        } else {
            $freeBytes = (int) disk_free_space($this->rootPath);
        }

        if ($freeBytes < $this->minDiskSpace) {
            $freeHuman     = round($freeBytes / 1024 / 1024, 1) . ' MB';
            $requiredHuman = round($this->minDiskSpace / 1024 / 1024, 1) . ' MB';
            throw new \RuntimeException(
                "Insufficient disk space (minimum {$requiredHuman} required, available: {$freeHuman})"
            );
        }

        if (!is_writable($this->rootPath)) {
            throw new \RuntimeException('Project root directory is not writable');
        }

        if ($version === $currentVersion) {
            throw new \RuntimeException("Version {$version} is already installed");
        }

        $existing = $this->database->findHistoryByVersion($version, ['completed']);
        if ($existing) {
            throw new \RuntimeException("Version {$version} has already been installed");
        }
    }

    /**
     * Verify that the installation was successful
     *
     * Checks: database connectivity, app_version in system_settings matches the
     * installed version, and all files listed in the manifest exist on disk.
     * Falls back to archive scan when manifest.files is empty.
     *
     * @param array  $manifest   Parsed manifest.json
     * @param string $version    Expected new version
     * @param string $extractDir Path to extracted patch (for scan-fallback file list)
     * @return array{success: bool, error: ?string}
     */
    private function verifyInstallation(array $manifest, string $version, string $extractDir): array
    {
        // Database connectivity check
        try {
            $pdo = $this->database->getPdo();
            $pdo->query("SELECT 1");
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Database connection test failed: ' . $e->getMessage()];
        }

        // Verify the stored version matches what was installed
        $currentVersion = $this->versionResolver->getCurrentVersion();
        if ($currentVersion !== $version) {
            return [
                'success' => false,
                'error'   => "Version mismatch: expected {$version}, got {$currentVersion}",
            ];
        }

        // Build file list for verification (mirror copyFiles scan-fallback logic)
        $filesList = $manifest['files'] ?? [];
        if (empty($filesList)) {
            $filesDir = $extractDir . '/files';
            if (is_dir($filesDir)) {
                $filesList = $this->fileManager->scanDirectory($filesDir, $filesDir);
            }
        }

        foreach ($filesList as $relativePath) {
            $destPath = $this->rootPath . '/' . $relativePath;

            if (!file_exists($destPath)) {
                return [
                    'success' => false,
                    'error'   => "Installed file missing: {$relativePath}",
                ];
            }

            // Compare size to archive source when available (zero-byte files are legitimate)
            $sourcePath = $extractDir . '/files/' . $relativePath;
            if (file_exists($sourcePath) && filesize($destPath) !== filesize($sourcePath)) {
                return [
                    'success' => false,
                    'error'   => "Installed file size mismatch: {$relativePath}",
                ];
            }
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Prune rollback artifacts (snapshots and DB backups) from old completed installs
     *
     * Keeps the most recent $keepLastSnapshots completed rows intact.
     * Deletes snapshot directories and backup files for all older completed installs.
     * Failed installs are intentionally retained for diagnostics and must be dismissed
     * manually. Rolled-back installs have their artifacts cleaned up during the rollback.
     *
     * @param int $currentPatchHistoryId The ID just completed (always kept)
     * @return void
     */
    private function pruneOldRollbackArtifacts(int $currentPatchHistoryId): void
    {
        try {
            $completed = $this->database->getHistory();
        } catch (\Exception $e) {
            $this->log("Patch prune: could not load history - " . $e->getMessage(), 'WARNING');
            return;
        }

        // Filter to completed rows only, sort newest first
        $completed = array_filter($completed, fn($r) => ($r['status'] ?? '') === 'completed');
        usort($completed, fn($a, $b) => strcmp($b['installed_at'] ?? '', $a['installed_at'] ?? ''));

        $kept = 0;
        foreach ($completed as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $kept++;
            if ($kept <= $this->keepLastSnapshots) {
                continue;
            }

            // Prune this old completed row
            if (!empty($row['backup_id']) && $this->backupAdapter !== null) {
                $this->backupAdapter->deleteBackup((int) $row['backup_id']);
                try {
                    $this->database->updateHistoryRecord($id, ['backup_id' => null]);
                } catch (\Exception $e) {
                    $this->log("Patch prune: could not clear backup_id for #{$id}: " . $e->getMessage(), 'WARNING');
                }
            }

            $this->fileManager->cleanupSnapshot($id);
        }
    }

    /**
     * Handle installation failure: rollback, cleanup, and log
     *
     * Prefixes the DB error_message with [error_code] when a code is known so
     * the code is greppable in the patch history UI without a schema change.
     *
     * @param \Exception  $e               The exception that caused the failure
     * @param int         $patchHistoryId  Patch history record ID
     * @param string      $version         Target version
     * @param int|null    $backupId        Backup ID if backup was created
     * @param string|null $extractDir      Extract directory path
     * @param string|null $downloadedFile  Downloaded file path
     * @param int|null    $userId          User who initiated the install
     * @param string|null $errorCode       Stable error code from the failed step
     * @param int|null    $retryAfter      Retry-After hint in seconds (for rate limiting)
     * @return array{success: bool, error: string, error_code: ?string, retry_after: ?int}
     */
    private function handleInstallFailure(
        \Exception $e,
        int $patchHistoryId,
        string $version,
        ?int $backupId,
        ?string $extractDir,
        ?string $downloadedFile,
        ?int $userId,
        ?string $errorCode = null,
        ?int $retryAfter = null
    ): array {
        $errorMsg   = $e->getMessage();
        $dbErrorMsg = $errorCode !== null ? "[{$errorCode}] {$errorMsg}" : $errorMsg;

        $this->log("Patch install failed: {$errorMsg}", 'ERROR');

        $failedStep = $this->progressTracker->getActiveStepId();
        $this->progressTracker->failProgress($failedStep);

        try {
            $this->database->updateHistoryRecord($patchHistoryId, [
                'status'        => 'failed',
                'error_message' => $dbErrorMsg,
            ]);
        } catch (\Exception $dbException) {
            $this->log("Could not update patch_history failure status: " . $dbException->getMessage(), 'WARNING');
        }

        $rollbackResult = null;
        $hasSnapshot    = $this->fileManager->hasSnapshot($patchHistoryId);
        if ($backupId || $hasSnapshot) {
            $this->log(
                "Patch install: attempting rollback (backup: " . ($backupId ?: 'none') .
                ", snapshot: " . ($hasSnapshot ? 'yes' : 'no') . ")",
                'WARNING'
            );
            $rollbackResult = $this->rollback($patchHistoryId);
        }

        if ($extractDir && is_dir($extractDir)) {
            $this->fileManager->cleanupDir($extractDir);
        }
        if ($downloadedFile && file_exists($downloadedFile)) {
            @unlink($downloadedFile);
        }

        $this->logActivity(
            'install_patch_failed',
            'patch',
            $patchHistoryId,
            null,
            ['version' => $version, 'error' => $errorMsg, 'rolled_back' => $rollbackResult['success'] ?? false],
            $userId
        );

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

        return [
            'success'     => false,
            'error'       => $resultError,
            'error_code'  => $errorCode,
            'retry_after' => $retryAfter,
        ];
    }

    /**
     * Log a message if logger is available
     *
     * @param string $message Log message
     * @param string $level   Log level
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
     * @param string      $action     Action identifier
     * @param string      $entityType Entity type
     * @param int|null    $entityId   Entity ID
     * @param array|null  $oldValues  Previous state
     * @param array|null  $newValues  New state
     * @param int|null    $userId     User who performed the action
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
