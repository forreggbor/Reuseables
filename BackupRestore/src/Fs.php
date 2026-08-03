<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Tiny shared filesystem utility (used by both BackupEngine and RestoreEngine
 * — kept as one static helper instead of duplicating the recursion in both).
 */

namespace BackupRestore;

use BackupRestore\Exec\Logger;

/**
 * @package BackupRestore
 */
final class Fs
{
    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * Best-effort: an unlink()/rmdir() failure (e.g. a permission issue) is
     * logged via the shared Exec\Logger channel but does not throw — a
     * partially-removed workdir is reclaimed later by
     * BackupEngine::cleanupTempFiles()'s 24h sweep, so this must not turn a
     * cleanup problem into a fatal error for the caller.
     *
     * @param string $dir Directory path to remove
     * @return void
     */
    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            Logger::log("[Fs] Could not read directory for removal: {$dir}", 'WARNING');
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            // A symlink to a directory must be checked BEFORE is_dir() — is_dir()
            // follows symlinks, so without this check a symlink placed inside an
            // extracted (untrusted) archive tree pointing outside $dir would be
            // recursed INTO and its target's real contents deleted. Removing the
            // link itself (never following it) is always correct regardless of
            // what it points to.
            if (is_link($path)) {
                if (!unlink($path)) {
                    Logger::log("[Fs] Failed to remove symlink: {$path}", 'WARNING');
                }
                continue;
            }

            if (is_dir($path)) {
                self::removeDirectory($path);
            } elseif (!unlink($path)) {
                Logger::log("[Fs] Failed to remove file: {$path}", 'WARNING');
            }
        }

        if (!rmdir($dir)) {
            Logger::log("[Fs] Failed to remove directory (orphan left for the next cleanupTempFiles() sweep): {$dir}", 'WARNING');
        }
    }
}
