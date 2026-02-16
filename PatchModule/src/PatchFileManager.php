<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\ArchiveAdapterInterface;
use PatchModule\Contracts\LoggerInterface;

/**
 * PatchFileManager - File extraction, copying, snapshot, and rollback
 *
 * Handles all file system operations during patch installation:
 * - Extracting patch archives and validating manifest
 * - Copying files from extracted patch to project root
 * - Creating selective file snapshots before overwriting
 * - Rolling back files from snapshots on failure
 * - OPcache invalidation and directory cleanup
 *
 * @package PatchModule
 */
class PatchFileManager
{
    /** @var ArchiveAdapterInterface */
    private ArchiveAdapterInterface $archiveAdapter;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var string Absolute path to the temp directory */
    private string $tempDir;

    /** @var string Absolute path to the project root */
    private string $rootPath;

    /**
     * @param ArchiveAdapterInterface $archiveAdapter Archive extraction adapter
     * @param string $tempDir Absolute path to the temp directory
     * @param string $rootPath Absolute path to the project root
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        ArchiveAdapterInterface $archiveAdapter,
        string $tempDir,
        string $rootPath,
        ?LoggerInterface $logger = null
    ) {
        $this->archiveAdapter = $archiveAdapter;
        $this->tempDir = $tempDir;
        $this->rootPath = $rootPath;
        $this->logger = $logger;
    }

    /**
     * Extract a patch .tgz archive and validate the manifest
     *
     * @param string $filePath Path to the .tgz file
     * @return array{success: bool, extract_dir: ?string, manifest: ?array, error: ?string}
     */
    public function extractPatch(string $filePath): array
    {
        $extractDir = $this->tempDir . '/patch_extract_' . time();

        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0775, true);
        }

        $result = $this->archiveAdapter->extract($filePath, $extractDir);
        if (!$result['success']) {
            $this->cleanupDir($extractDir);
            return [
                'success' => false,
                'extract_dir' => null,
                'manifest' => null,
                'error' => 'Extract failed: ' . $result['error'],
            ];
        }

        // Look for manifest.json (may be at root or in a subdirectory)
        $manifestPath = $extractDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            // Check one level deeper (tar may create a wrapper directory)
            $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
            if (count($dirs) === 1 && file_exists($dirs[0] . '/manifest.json')) {
                $manifestPath = $dirs[0] . '/manifest.json';
                $extractDir = $dirs[0];
            } else {
                $this->cleanupDir($extractDir);
                return [
                    'success' => false,
                    'extract_dir' => null,
                    'manifest' => null,
                    'error' => 'manifest.json not found in patch archive',
                ];
            }
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['version'])) {
            $this->cleanupDir($extractDir);
            return [
                'success' => false,
                'extract_dir' => null,
                'manifest' => null,
                'error' => 'Invalid manifest.json: missing version field',
            ];
        }

        $this->log("Patch extracted to: {$extractDir}, version: {$manifest['version']}", 'DEBUG');

        return ['success' => true, 'extract_dir' => $extractDir, 'manifest' => $manifest, 'error' => null];
    }

    /**
     * Copy files from extracted patch to project root
     *
     * Reads the files list from manifest and copies each file, preserving
     * directory structure. Creates parent directories as needed.
     * Invalidates OPcache per file.
     *
     * @param string $extractDir Path to extracted patch directory
     * @param array $manifest Parsed manifest.json
     * @return array{success: bool, copied_count: int, error: ?string}
     */
    public function copyFiles(string $extractDir, array $manifest): array
    {
        $filesDir = $extractDir . '/files';
        if (!is_dir($filesDir)) {
            return ['success' => true, 'copied_count' => 0, 'error' => null];
        }

        $copiedCount = 0;
        $filesList = $manifest['files'] ?? [];

        // If no files list in manifest, scan the files directory
        if (empty($filesList)) {
            $filesList = $this->scanDirectory($filesDir, $filesDir);
        }

        foreach ($filesList as $relativePath) {
            $sourcePath = $filesDir . '/' . $relativePath;
            $destPath = $this->rootPath . '/' . $relativePath;

            if (!file_exists($sourcePath)) {
                $this->log("Patch file not found in archive: {$relativePath}", 'WARNING');
                continue;
            }

            // Create parent directory if needed
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                if (!mkdir($destDir, 0775, true)) {
                    return [
                        'success' => false,
                        'copied_count' => $copiedCount,
                        'error' => "Cannot create directory: {$destDir}",
                    ];
                }
            }

            if (!copy($sourcePath, $destPath)) {
                return [
                    'success' => false,
                    'copied_count' => $copiedCount,
                    'error' => "Failed to copy: {$relativePath}",
                ];
            }

            // Invalidate OPcache for this specific file
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($destPath, true);
            }

            $copiedCount++;
        }

        return ['success' => true, 'copied_count' => $copiedCount, 'error' => null];
    }

    /**
     * Create a snapshot of files that will be affected by the patch
     *
     * Backs up only the files listed in the manifest. Files that don't exist
     * yet (new files from the patch) are recorded in metadata so they can be
     * deleted during rollback.
     *
     * @param int $patchHistoryId patch_history record ID (used in snapshot path)
     * @param array $manifest Parsed manifest.json
     * @param string $extractDir Path to extracted patch directory
     * @return array{success: bool, snapshot_dir: ?string, error: ?string}
     */
    public function backupAffectedFiles(int $patchHistoryId, array $manifest, string $extractDir): array
    {
        $snapshotDir = $this->tempDir . '/patch_snapshot_' . $patchHistoryId;

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0775, true);
        }

        $filesDir = $extractDir . '/files';
        $filesList = $manifest['files'] ?? [];

        if (empty($filesList) && is_dir($filesDir)) {
            $filesList = $this->scanDirectory($filesDir, $filesDir);
        }

        $backedUp = [];
        $newFiles = [];

        foreach ($filesList as $relativePath) {
            $projectPath = $this->rootPath . '/' . $relativePath;

            if (file_exists($projectPath)) {
                // Existing file — back it up
                $snapshotPath = $snapshotDir . '/files/' . $relativePath;
                $snapshotFileDir = dirname($snapshotPath);

                if (!is_dir($snapshotFileDir)) {
                    if (!mkdir($snapshotFileDir, 0775, true)) {
                        return [
                            'success' => false,
                            'snapshot_dir' => null,
                            'error' => "Cannot create snapshot directory: {$snapshotFileDir}",
                        ];
                    }
                }

                if (!copy($projectPath, $snapshotPath)) {
                    return [
                        'success' => false,
                        'snapshot_dir' => null,
                        'error' => "Cannot snapshot file: {$relativePath}",
                    ];
                }

                $backedUp[] = $relativePath;
            } else {
                // New file — record for deletion on rollback
                $newFiles[] = $relativePath;
            }
        }

        // Write snapshot metadata
        $metaData = [
            'patch_history_id' => $patchHistoryId,
            'created_at' => date('Y-m-d H:i:s'),
            'files_backed_up' => $backedUp,
            'new_files' => $newFiles,
        ];
        file_put_contents(
            $snapshotDir . '/snapshot_meta.json',
            json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->log(
            "Patch file snapshot created: " . count($backedUp) . " backed up, " . count($newFiles) . " new",
            'DEBUG'
        );

        return ['success' => true, 'snapshot_dir' => $snapshotDir, 'error' => null];
    }

    /**
     * Restore files from a patch snapshot (selective rollback)
     *
     * Restores only the files that were backed up before the patch and deletes
     * any new files that were added by the patch.
     *
     * @param int $patchHistoryId patch_history record ID
     * @return array{success: bool, restored_count: int, deleted_count: int, error: ?string}
     */
    public function rollbackFiles(int $patchHistoryId): array
    {
        $snapshotDir = $this->tempDir . '/patch_snapshot_' . $patchHistoryId;
        $metaFile = $snapshotDir . '/snapshot_meta.json';

        if (!file_exists($metaFile)) {
            return [
                'success' => false,
                'restored_count' => 0,
                'deleted_count' => 0,
                'error' => 'Snapshot metadata not found',
            ];
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (!is_array($meta)) {
            return [
                'success' => false,
                'restored_count' => 0,
                'deleted_count' => 0,
                'error' => 'Invalid snapshot metadata',
            ];
        }

        $restoredCount = 0;
        $deletedCount = 0;

        // Restore backed-up files
        foreach ($meta['files_backed_up'] ?? [] as $relativePath) {
            $snapshotPath = $snapshotDir . '/files/' . $relativePath;
            $projectPath = $this->rootPath . '/' . $relativePath;

            if (!file_exists($snapshotPath)) {
                $this->log("Patch rollback: snapshot file missing - {$relativePath}", 'WARNING');
                continue;
            }

            $destDir = dirname($projectPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }

            if (copy($snapshotPath, $projectPath)) {
                $restoredCount++;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($projectPath, true);
                }
            } else {
                $this->log("Patch rollback: failed to restore - {$relativePath}", 'ERROR');
            }
        }

        // Delete new files that the patch added
        foreach ($meta['new_files'] ?? [] as $relativePath) {
            $projectPath = $this->rootPath . '/' . $relativePath;
            if (file_exists($projectPath)) {
                if (@unlink($projectPath)) {
                    $deletedCount++;
                } else {
                    $this->log("Patch rollback: failed to delete new file - {$relativePath}", 'WARNING');
                }
            }
        }

        $this->log("Patch file rollback: {$restoredCount} restored, {$deletedCount} deleted", 'INFO');

        return ['success' => true, 'restored_count' => $restoredCount, 'deleted_count' => $deletedCount, 'error' => null];
    }

    /**
     * Check if a snapshot exists for a given patch history ID
     *
     * @param int $patchHistoryId patch_history record ID
     * @return bool
     */
    public function hasSnapshot(int $patchHistoryId): bool
    {
        return is_dir($this->tempDir . '/patch_snapshot_' . $patchHistoryId);
    }

    /**
     * Clean up a snapshot directory
     *
     * @param int $patchHistoryId patch_history record ID
     * @return void
     */
    public function cleanupSnapshot(int $patchHistoryId): void
    {
        $snapshotDir = $this->tempDir . '/patch_snapshot_' . $patchHistoryId;
        if (is_dir($snapshotDir)) {
            $this->cleanupDir($snapshotDir);
        }
    }

    /**
     * Reset OPcache completely
     *
     * @return void
     */
    public function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Recursively scan a directory and return relative file paths
     *
     * @param string $dir Directory to scan
     * @param string $baseDir Base directory for calculating relative paths
     * @return string[] List of relative file paths
     */
    public function scanDirectory(string $dir, string $baseDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($baseDir) + 1);
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    /**
     * Recursively delete a directory and its contents
     *
     * @param string $dir Directory path
     * @return void
     */
    public function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
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
}