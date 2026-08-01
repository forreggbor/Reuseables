<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Exclusive backup/restore mutual-exclusion lock — shared by BackupEngine
 * (wraps createBackup) and the facade's restore orchestration (wraps the
 * full database+files restore sequence).
 */

namespace BackupRestore;

/**
 * @package BackupRestore
 */
final class Lock
{
    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Run a callback while holding the exclusive backup/restore mutual-exclusion lock.
     *
     * Ensures at most one backup or restore operation runs at a time, across both
     * on-demand calls and a scheduled/cron trigger, preventing concurrent operations
     * from racing on shared `_bak_*`/`_restore_*`/`_old_*` table names or backup
     * workdir paths. Non-blocking: if the lock is already held, fails immediately
     * instead of queueing.
     *
     * Lock scope is intentionally single-level per entry point: BackupEngine::
     * createBackup() takes this lock itself (covering both on-demand and
     * scheduled/cron triggers), while the restore chain is locked once by the
     * facade around the full database+files restore sequence — never by the
     * individual restore steps — so the lock is not released between phases.
     *
     * @param string $tempPath Absolute temp/scratch directory (lock file lives at
     *                         $tempPath/backup.lock)
     * @param callable $callback Operation to run while the lock is held; must return
     *                           an array shaped like the other Backup* result arrays.
     * @param callable(string,string):void $logger
     * @param Translator $t
     * @return array{success: bool, error?: string, lock_failed?: bool} The callback's
     *         return value, or a failure array with `lock_failed: true` if the lock
     *         could not be acquired.
     */
    public static function withLock(string $tempPath, callable $callback, callable $logger, Translator $t): array
    {
        $tempPath = rtrim($tempPath, '/');
        if (!is_dir($tempPath)) {
            mkdir($tempPath, 0775, true);
        }

        // A broken host logger must never break a backup/restore operation —
        // every direct $logger(...) call below is wrapped accordingly.
        $safeLog = static function (string $message, string $level) use ($logger): void {
            try {
                $logger($message, $level);
            } catch (\Throwable) {
            }
        };

        $lockPath = $tempPath . '/backup.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            $safeLog("[Backup/Lock] Could not open lock file: {$lockPath}", 'ERROR');
            return ['success' => false, 'error' => $t->translate('TEXT_BACKUP_LOCK_UNAVAILABLE'), 'lock_failed' => true];
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $safeLog("[Backup/Lock] Lock busy — another backup/restore operation is already running", 'WARNING');
            return ['success' => false, 'error' => $t->translate('TEXT_BACKUP_ALREADY_RUNNING'), 'lock_failed' => true];
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
