<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\ArchiveAdapterInterface;
use PatchModule\Contracts\LoggerInterface;

/**
 * PatchFileManager - File extraction, copying, snapshot, and rollback
 *
 * Handles all file system operations during patch installation:
 * - Extracting patch archives and validating the manifest
 * - Rejecting archives that contain symbolic links (path-traversal / data-exfiltration risk)
 * - Copying files from extracted patch to project root with path-traversal protection
 * - Removing obsolete files listed in manifest.removed_files
 * - Creating selective file snapshots before overwriting or deleting
 * - Rolling back files from snapshots on failure
 * - OPcache invalidation and directory cleanup
 *
 * All paths derived from the manifest or snapshot_meta.json are validated through
 * safeJoin() before any file system operation.
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
     * @param string                  $tempDir        Absolute path to the temp directory
     * @param string                  $rootPath       Absolute path to the project root
     * @param LoggerInterface|null    $logger         Optional logger
     */
    public function __construct(
        ArchiveAdapterInterface $archiveAdapter,
        string $tempDir,
        string $rootPath,
        ?LoggerInterface $logger = null
    ) {
        $this->archiveAdapter = $archiveAdapter;
        $this->tempDir        = $tempDir;
        $this->rootPath       = $rootPath;
        $this->logger         = $logger;
    }

    /**
     * Extract a patch .tgz archive and validate the manifest
     *
     * Rejects the archive if any symbolic link is found in the extracted tree.
     *
     * @param string $filePath Path to the .tgz file
     * @return array{success: bool, extract_dir: ?string, manifest: ?array, error: ?string, error_code: ?string}
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
                'success'     => false,
                'extract_dir' => null,
                'manifest'    => null,
                'error'       => 'Extract failed: ' . $result['error'],
                'error_code'  => null,
            ];
        }

        // Reject archives containing symbolic links
        $symlink = $this->findFirstSymlink($extractDir);
        if ($symlink !== null) {
            $this->cleanupDir($extractDir);
            $this->log('Patch archive rejected: contains symbolic link at ' . basename($symlink), 'ERROR');
            return [
                'success'     => false,
                'extract_dir' => null,
                'manifest'    => null,
                'error'       => 'Archive contains symbolic link (security policy)',
                'error_code'  => 'invalid_archive',
            ];
        }

        // Look for manifest.json (may be at root or in a subdirectory)
        $manifestPath = $extractDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            // Check one level deeper (tar may create a wrapper directory)
            $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
            if (count($dirs) === 1 && file_exists($dirs[0] . '/manifest.json')) {
                $manifestPath = $dirs[0] . '/manifest.json';
                $extractDir   = $dirs[0];
            } else {
                $this->cleanupDir($extractDir);
                return [
                    'success'     => false,
                    'extract_dir' => null,
                    'manifest'    => null,
                    'error'       => 'manifest.json not found in patch archive',
                    'error_code'  => null,
                ];
            }
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['version'])) {
            $this->cleanupDir($extractDir);
            return [
                'success'     => false,
                'extract_dir' => null,
                'manifest'    => null,
                'error'       => 'Invalid manifest.json: missing version field',
                'error_code'  => null,
            ];
        }

        $this->log("Patch extracted to: {$extractDir}, version: {$manifest['version']}", 'DEBUG');

        return [
            'success'     => true,
            'extract_dir' => $extractDir,
            'manifest'    => $manifest,
            'error'       => null,
            'error_code'  => null,
        ];
    }

    /**
     * Copy files from extracted patch to project root
     *
     * Reads the files list from manifest and copies each file, preserving
     * directory structure. Creates parent directories as needed.
     * Each path is validated against the project root to block traversal.
     * Invalidates OPcache per file.
     *
     * @param string $extractDir Path to extracted patch directory
     * @param array  $manifest   Parsed manifest.json
     * @return array{success: bool, copied_count: int, error: ?string, error_code: ?string}
     */
    public function copyFiles(string $extractDir, array $manifest): array
    {
        $filesDir = $extractDir . '/files';
        if (!is_dir($filesDir)) {
            return ['success' => true, 'copied_count' => 0, 'error' => null, 'error_code' => null];
        }

        $copiedCount = 0;
        $filesList   = $manifest['files'] ?? [];

        if (empty($filesList)) {
            $filesList = $this->scanDirectory($filesDir, $filesDir);
        }

        foreach ($filesList as $relativePath) {
            try {
                $destPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $safe = substr($relativePath, 0, 200);
                $this->log("Patch path traversal blocked in files list: {$safe}", 'ERROR');
                return [
                    'success'      => false,
                    'copied_count' => $copiedCount,
                    'error'        => 'Path traversal attempt blocked in manifest files list',
                    'error_code'   => 'invalid_manifest_path',
                ];
            }

            $sourcePath = $filesDir . '/' . $relativePath;

            if (!file_exists($sourcePath)) {
                $this->log("Patch file not found in archive: {$relativePath}", 'WARNING');
                continue;
            }

            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                if (!mkdir($destDir, 0775, true)) {
                    return [
                        'success'      => false,
                        'copied_count' => $copiedCount,
                        'error'        => "Cannot create directory: {$destDir}",
                        'error_code'   => null,
                    ];
                }
            }

            if (!copy($sourcePath, $destPath)) {
                return [
                    'success'      => false,
                    'copied_count' => $copiedCount,
                    'error'        => "Failed to copy: {$relativePath}",
                    'error_code'   => null,
                ];
            }

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($destPath, true);
            }

            $copiedCount++;
        }

        return ['success' => true, 'copied_count' => $copiedCount, 'error' => null, 'error_code' => null];
    }

    /**
     * Remove obsolete files listed in manifest.removed_files
     *
     * Files absent from disk are treated as already removed (idempotent).
     * Each path is validated against the project root to block traversal.
     * Invalidates OPcache per removed file.
     *
     * @param array $manifest Parsed manifest.json
     * @return array{success: bool, removed_count: int, error: ?string, error_code: ?string}
     */
    public function removeFiles(array $manifest): array
    {
        $removedCount = 0;
        $filesList    = $manifest['removed_files'] ?? [];

        foreach ($filesList as $relativePath) {
            try {
                $projectPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $safe = substr($relativePath, 0, 200);
                $this->log("Patch path traversal blocked in removed_files list: {$safe}", 'ERROR');
                return [
                    'success'       => false,
                    'removed_count' => $removedCount,
                    'error'         => 'Path traversal attempt blocked in manifest removed_files list',
                    'error_code'    => 'invalid_manifest_path',
                ];
            }

            if (!file_exists($projectPath)) {
                $this->log("Patch remove: file already absent: {$relativePath}", 'INFO');
                continue;
            }

            if (@unlink($projectPath)) {
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($projectPath, true);
                }
                $removedCount++;
            } else {
                $this->log("Patch remove: failed to delete: {$relativePath}", 'WARNING');
            }
        }

        return ['success' => true, 'removed_count' => $removedCount, 'error' => null, 'error_code' => null];
    }

    /**
     * Create a snapshot of files that will be affected by the patch
     *
     * Backs up existing files from manifest.files (which will be overwritten) and
     * existing files from manifest.removed_files (which will be deleted). New files
     * that don't yet exist are recorded in metadata so they can be deleted during rollback.
     *
     * @param int    $patchHistoryId patch_history record ID (used in snapshot path)
     * @param array  $manifest       Parsed manifest.json
     * @param string $extractDir     Path to extracted patch directory
     * @return array{success: bool, snapshot_dir: ?string, error: ?string, error_code: ?string}
     */
    public function backupAffectedFiles(int $patchHistoryId, array $manifest, string $extractDir): array
    {
        $snapshotDir = $this->tempDir . '/patch_snapshot_' . $patchHistoryId;

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0775, true);
        }

        $filesDir  = $extractDir . '/files';
        $filesList = $manifest['files'] ?? [];

        if (empty($filesList) && is_dir($filesDir)) {
            $filesList = $this->scanDirectory($filesDir, $filesDir);
        }

        $backedUp = [];
        $newFiles = [];

        foreach ($filesList as $relativePath) {
            try {
                $projectPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $safe = substr($relativePath, 0, 200);
                $this->log("Patch path traversal blocked during snapshot (files): {$safe}", 'ERROR');
                return [
                    'success'      => false,
                    'snapshot_dir' => null,
                    'error'        => 'Path traversal attempt blocked in manifest files list',
                    'error_code'   => 'invalid_manifest_path',
                ];
            }

            if (file_exists($projectPath)) {
                $snapshotPath    = $snapshotDir . '/files/' . $relativePath;
                $snapshotFileDir = dirname($snapshotPath);

                if (!is_dir($snapshotFileDir)) {
                    if (!mkdir($snapshotFileDir, 0775, true)) {
                        return [
                            'success'      => false,
                            'snapshot_dir' => null,
                            'error'        => "Cannot create snapshot directory: {$snapshotFileDir}",
                            'error_code'   => null,
                        ];
                    }
                }

                if (!copy($projectPath, $snapshotPath)) {
                    return [
                        'success'      => false,
                        'snapshot_dir' => null,
                        'error'        => "Cannot snapshot file: {$relativePath}",
                        'error_code'   => null,
                    ];
                }

                $backedUp[] = $relativePath;
            } else {
                $newFiles[] = $relativePath;
            }
        }

        // Backup files scheduled for removal (so rollback can restore them)
        $filesToRemove     = [];
        $removedFilesList  = $manifest['removed_files'] ?? [];

        foreach ($removedFilesList as $relativePath) {
            try {
                $projectPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $safe = substr($relativePath, 0, 200);
                $this->log("Patch path traversal blocked during snapshot (removed_files): {$safe}", 'ERROR');
                return [
                    'success'      => false,
                    'snapshot_dir' => null,
                    'error'        => 'Path traversal attempt blocked in manifest removed_files list',
                    'error_code'   => 'invalid_manifest_path',
                ];
            }

            $filesToRemove[] = $relativePath;

            if (file_exists($projectPath)) {
                $snapshotPath    = $snapshotDir . '/files/' . $relativePath;
                $snapshotFileDir = dirname($snapshotPath);

                if (!is_dir($snapshotFileDir)) {
                    if (!mkdir($snapshotFileDir, 0775, true)) {
                        return [
                            'success'      => false,
                            'snapshot_dir' => null,
                            'error'        => "Cannot create snapshot directory: {$snapshotFileDir}",
                            'error_code'   => null,
                        ];
                    }
                }

                if (!copy($projectPath, $snapshotPath)) {
                    return [
                        'success'      => false,
                        'snapshot_dir' => null,
                        'error'        => "Cannot snapshot removed file: {$relativePath}",
                        'error_code'   => null,
                    ];
                }
            }
        }

        $metaData = [
            'patch_history_id' => $patchHistoryId,
            'created_at'       => date('Y-m-d H:i:s'),
            'files_backed_up'  => $backedUp,
            'new_files'        => $newFiles,
            'files_to_remove'  => $filesToRemove,
        ];
        file_put_contents(
            $snapshotDir . '/snapshot_meta.json',
            json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->log(
            "Patch file snapshot created: " . count($backedUp) . " backed up, " .
            count($newFiles) . " new, " . count($filesToRemove) . " to remove",
            'DEBUG'
        );

        return ['success' => true, 'snapshot_dir' => $snapshotDir, 'error' => null, 'error_code' => null];
    }

    /**
     * Restore files from a patch snapshot (selective rollback)
     *
     * Restores files backed up before the patch, deletes any new files that the patch
     * added, and restores any files that the patch deleted (from files_to_remove list).
     *
     * @param int $patchHistoryId patch_history record ID
     * @return array{success: bool, restored_count: int, deleted_count: int, error: ?string}
     */
    public function rollbackFiles(int $patchHistoryId): array
    {
        $snapshotDir = $this->tempDir . '/patch_snapshot_' . $patchHistoryId;
        $metaFile    = $snapshotDir . '/snapshot_meta.json';

        if (!file_exists($metaFile)) {
            return [
                'success'        => false,
                'restored_count' => 0,
                'deleted_count'  => 0,
                'error'          => 'Snapshot metadata not found',
            ];
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (!is_array($meta)) {
            return [
                'success'        => false,
                'restored_count' => 0,
                'deleted_count'  => 0,
                'error'          => 'Invalid snapshot metadata',
            ];
        }

        $restoredCount = 0;
        $deletedCount  = 0;

        // Restore backed-up files
        foreach ($meta['files_backed_up'] ?? [] as $relativePath) {
            try {
                $projectPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $this->log("Patch rollback: traversal in snapshot meta (backed_up): {$relativePath}", 'ERROR');
                continue;
            }

            $snapshotPath = $snapshotDir . '/files/' . $relativePath;

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
            try {
                $projectPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $this->log("Patch rollback: traversal in snapshot meta (new_files): {$relativePath}", 'ERROR');
                continue;
            }

            if (file_exists($projectPath)) {
                if (@unlink($projectPath)) {
                    $deletedCount++;
                } else {
                    $this->log("Patch rollback: failed to delete new file - {$relativePath}", 'WARNING');
                }
            }
        }

        // Restore files that the patch removed
        foreach ($meta['files_to_remove'] ?? [] as $relativePath) {
            try {
                $projectPath = $this->safeJoin($this->rootPath, $relativePath);
            } catch (\InvalidArgumentException $e) {
                $this->log("Patch rollback: traversal in snapshot meta (files_to_remove): {$relativePath}", 'ERROR');
                continue;
            }

            $snapshotPath = $snapshotDir . '/files/' . $relativePath;

            if (!file_exists($snapshotPath)) {
                // File did not exist before the patch — nothing to restore
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
                $this->log("Patch rollback: failed to restore removed file - {$relativePath}", 'ERROR');
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
     * Symbolic links are skipped to prevent processing of link targets outside
     * the expected tree.
     *
     * @param string $dir     Directory to scan
     * @param string $baseDir Base directory for calculating relative paths
     * @return string[] List of relative file paths
     */
    public function scanDirectory(string $dir, string $baseDir): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                continue;
            }
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($baseDir) + 1);
                $files[]      = $relativePath;
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
     * Resolve a relative path safely against a base directory
     *
     * Rejects empty paths, absolute paths, Windows drive letters, backslashes,
     * null bytes, and any path segment that is "..", ".", or empty (from "//").
     * Performs a realpath check against the resolved base to catch any remaining
     * escapes via existing symlinks in the base tree.
     *
     * @param string $base         Absolute base directory (must exist)
     * @param string $relativePath Relative path from the manifest or snapshot metadata
     * @return string Resolved absolute path
     * @throws \InvalidArgumentException When traversal or invalid path is detected
     */
    private function safeJoin(string $base, string $relativePath): string
    {
        if ($relativePath === '') {
            throw new \InvalidArgumentException('Path cannot be empty');
        }

        if ($relativePath[0] === '/' || preg_match('~^[a-zA-Z]:~', $relativePath)) {
            throw new \InvalidArgumentException("Absolute path rejected: {$relativePath}");
        }

        if (str_contains($relativePath, '\\') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException("Invalid characters in path: {$relativePath}");
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                throw new \InvalidArgumentException("Traversal segment rejected: {$relativePath}");
            }
        }

        $resolvedBase = realpath($base);
        if ($resolvedBase === false) {
            throw new \InvalidArgumentException("Base path does not exist: {$base}");
        }

        return $resolvedBase . '/' . $relativePath;
    }

    /**
     * Walk a directory tree and return the path of the first symbolic link found
     *
     * @param string $dir Directory to scan
     * @return string|null Path to first symlink, or null if none found
     */
    private function findFirstSymlink(string $dir): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return $item->getPathname();
            }
        }

        return null;
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
}
