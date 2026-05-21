<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Per-job file locking with POSIX stale-PID detection and mtime fallback.
 */

declare(strict_types=1);

namespace CronAdmin;

use CronAdmin\Contracts\LoggerInterface;

/**
 * Manages per-job exclusive file locks in a shared lock directory.
 *
 * Uses POSIX flock(LOCK_EX|LOCK_NB) on <lock_dir>/<job_key>.lock.
 * The PID of the locking process is written into the lock file so that a
 * subsequent dispatch tick can probe liveness before reclaiming a stale lock.
 *
 * POSIX-only PID probe: posix_kill() is available on Linux/macOS; on Windows
 * the probe is skipped and mtime-based staleness is the sole mechanism.
 *
 * EPERM edge case: posix_kill($pid, 0) returns true both when the PID exists
 * AND when the caller lacks permission to signal it. The module treats EPERM
 * as "alive" (conservative) and relies on the mtime timeout as the fallback
 * reclaim mechanism.
 */
class LockManager
{
    /**
     * @param string          $lockDir  Absolute path to the directory holding lock files.
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly string $lockDir,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Acquires the per-job exclusive non-blocking flock.
     *
     * Stale-lock recovery: probes PID liveness whenever a lock file exists —
     * not only after the mtime timeout. This ensures OOM-killed or SIGKILL'd
     * processes are detected immediately on the next tick instead of blocking
     * for the full lock_timeout_seconds window.
     *
     * Recovery paths:
     * - PID dead (posix_kill returns false): unlink and re-acquire.
     * - PID alive but mtime exceeds timeout: log warning, skip.
     * - PID alive within timeout: lock legitimately held, skip.
     * - posix_kill unavailable (Windows) or PID unreadable: fall back to mtime.
     *
     * @param string $jobKey          Must match /^[a-z0-9_]+$/.
     * @param int    $timeoutSeconds  Stale-lock reclaim threshold.
     * @return resource|null  Open file handle on success; null when the lock is held.
     * @throws \InvalidArgumentException  When $jobKey contains invalid characters.
     */
    public function acquire(string $jobKey, int $timeoutSeconds): mixed
    {
        if (!preg_match('/^[a-z0-9_]+$/', $jobKey)) {
            throw new \InvalidArgumentException(
                "CronAdmin LockManager: invalid jobKey '{$jobKey}'. Must match /^[a-z0-9_]+\$/."
            );
        }

        $lockFile = $this->lockDir . '/' . $jobKey . '.lock';

        // Stale-lock probe — runs whenever the lock file exists.
        if (file_exists($lockFile)) {
            $pid = (int) trim((string) @file_get_contents($lockFile));

            if ($pid > 0 && function_exists('posix_kill')) {
                // POSIX: probe PID liveness directly (EPERM → treated as alive).
                $processAlive = posix_kill($pid, 0);

                if (!$processAlive) {
                    // Dead PID (OOM, SIGKILL, crash) — reclaim immediately.
                    $this->logger->info(
                        "Cron task '{$jobKey}': lock held by dead PID {$pid} — reclaiming."
                    );
                    @unlink($lockFile);
                } else {
                    // Process is alive. Honour the lock unless it has timed out.
                    $mtime = @filemtime($lockFile);
                    if ($mtime !== false && (time() - $mtime) > $timeoutSeconds) {
                        $this->logger->warning(
                            "Cron task '{$jobKey}': lock timeout ({$timeoutSeconds}s) exceeded but PID {$pid} is still alive — skipping."
                        );
                    }
                    return null;
                }
            } else {
                // posix_kill unavailable or PID unreadable — fall back to mtime.
                $mtime = @filemtime($lockFile);
                if ($mtime === false || (time() - $mtime) <= $timeoutSeconds) {
                    return null;
                }
                $this->logger->warning(
                    "Cron task '{$jobKey}': lock timeout ({$timeoutSeconds}s) exceeded, no PID available — reclaiming."
                );
                @unlink($lockFile);
            }
        }

        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            $this->logger->error("Cron task '{$jobKey}': cannot open lock file '{$lockFile}'.");
            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }

        // Record PID so the next tick can probe liveness.
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) getmypid());
        fflush($fp);

        return $fp;
    }

    /**
     * Releases the lock acquired by acquire() and closes the file handle.
     *
     * Safe to call with null (no-op) — allows finally-block release without
     * a null check at the call site.
     *
     * @param resource|null $fp
     * @return void
     */
    public function release(mixed $fp): void
    {
        if ($fp !== null && is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Acquires the sync flock used to serialise ManifestSyncService calls across concurrent ticks.
     *
     * Non-blocking: returns null immediately when another tick holds the lock.
     * Callers MUST release via releaseSyncLock() in a finally block.
     *
     * @return resource|null  File handle on success; null when another tick holds it.
     */
    public function acquireSyncLock(): mixed
    {
        $lockFile = $this->lockDir . '/.sync.lock';
        $fp       = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    /**
     * Releases the sync lock acquired by acquireSyncLock().
     *
     * @param resource|null $fp
     * @return void
     */
    public function releaseSyncLock(mixed $fp): void
    {
        $this->release($fp);
    }
}
