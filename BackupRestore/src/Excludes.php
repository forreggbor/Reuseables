<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Path-exclusion list helpers shared by BackupEngine (file-archive creation,
 * directory-tree UI) and RestoreEngine (pre-restore snapshot, forward sync,
 * rollback sync — all three MUST use the identical exclusion list, see
 * fileSync() below).
 */

namespace BackupRestore;

/**
 * @package BackupRestore
 */
final class Excludes
{
    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Default-excluded directories a host may override per backup profile.
     *
     * @return array<int,string>
     */
    public static function defaultExcluded(): array
    {
        return ['storage/temp', 'storage/cache', 'node_modules'];
    }

    /**
     * Get always-excluded directories (backup/restore internals).
     *
     * Derived from the REAL configured backup directory, not a hardcoded
     * relative string — a customized storage path still resolves to the
     * correct exclusion path instead of silently failing to match and
     * letting a backup archive itself. Also excludes `.git`.
     *
     * @param string $rootPath Absolute project root
     * @param string $backupDir Absolute backup-archive storage directory
     * @return array<int,string> List of always-excluded paths, relative to $rootPath
     */
    public static function always(string $rootPath, string $backupDir): array
    {
        $rootDir = rtrim($rootPath, '/');

        $excluded = [];
        $absolutePath = rtrim($backupDir, '/');
        if (str_starts_with($absolutePath, $rootDir . '/')) {
            $excluded[] = substr($absolutePath, strlen($rootDir) + 1);
        }
        $excluded[] = '.git';

        return array_values(array_unique($excluded));
    }

    /**
     * Get the exclusion list shared by ALL file-sync operations that touch the
     * live project root during restore: the pre-restore snapshot, the forward
     * restore sync, and the rollback sync.
     *
     * This list MUST be identical across all three call sites. If the snapshot
     * excludes a path (e.g. the backup dir, to avoid duplicating gigabytes of
     * archives) but the rollback sync does not pass the same exclude,
     * rollback's `rsync --delete` would delete that path from the live tree —
     * including, in the backup-dir case, every backup on the server. Unlike
     * {@see always()} (used for backup-creation archiving, where the temp path
     * stays user-overridable via profiles), the temp path is unconditionally
     * excluded here because the snapshot/extraction workdirs and the
     * restore-maintenance flag live inside it.
     *
     * Also unconditionally excludes `node_modules` — it is never present in a
     * backup archive in the first place (already excluded from
     * backup-creation archiving), so walking/copying it into the pre-restore
     * snapshot only wastes disk space and time; leaving it out of the
     * exclusion list here also meant the forward sync's `rsync --delete`
     * would remove a live node_modules tree entirely.
     *
     * @param string $rootPath Absolute project root
     * @param string $backupDir Absolute backup-archive storage directory
     * @param string $tempPath Absolute temp/scratch directory
     * @return array<int,string> List of excluded paths, relative to $rootPath
     */
    public static function fileSync(string $rootPath, string $backupDir, string $tempPath): array
    {
        $rootDir = rtrim($rootPath, '/');
        $tempDir = rtrim($tempPath, '/');

        $excluded = self::always($rootPath, $backupDir);
        if (str_starts_with($tempDir, $rootDir . '/')) {
            $excluded[] = substr($tempDir, strlen($rootDir) + 1);
        }
        $excluded[] = 'node_modules';

        return array_values(array_unique($excluded));
    }

    /**
     * Get the absolute path of the restore-maintenance flag file.
     *
     * Written before the destructive database/file swap begins and removed
     * once a consistent state is reached (success or a verified rollback).
     * Lives under the temp path so it is always covered by {@see fileSync()}
     * and never wiped by the restore sync itself.
     *
     * @param string $tempPath Absolute temp/scratch directory
     * @return string Absolute path to the flag file
     */
    public static function restoreMaintenanceFlagPath(string $tempPath): string
    {
        return rtrim($tempPath, '/') . '/.restore_maintenance';
    }
}
