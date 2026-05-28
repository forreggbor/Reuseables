<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Main cron dispatcher loop: manifest sync, schedule matching, and job execution.
 */

declare(strict_types=1);

namespace CronAdmin;

use CronAdmin\Contracts\DatabaseAdapterInterface;
use CronAdmin\Contracts\DispatcherKillSwitchAdapterInterface;
use CronAdmin\Contracts\LoggerInterface;
use CronAdmin\Exceptions\InvalidManifestException;
use CronAdmin\Tasks\CronTaskResult;

/**
 * Orchestrates all scheduled cron jobs on each dispatch tick.
 *
 * Designed to be called once per minute from cron/run.php via `* * * * *`.
 * The Scheduler assumes 1-minute granularity — a 5-minute crontab cadence
 * will silently miss between-tick triggers.
 *
 * Responsibilities:
 *  - Kill-switch gate (skips execution but still syncs manifest)
 *  - Lock-directory creation (loud failure on error)
 *  - Manifest mtime-triggered sync (with sync flock to prevent double-write)
 *  - Per-job schedule matching and manual-trigger claim
 *  - Delegates execution to JobRunner (per-job lock, output capture, persist)
 */
class Dispatcher
{
    /** @var string Filename that stores the last-synced manifest mtime. */
    private const MTIME_MARKER = '.manifest_mtime';

    /**
     * @param DatabaseAdapterInterface             $db
     * @param DispatcherKillSwitchAdapterInterface $killSwitch
     * @param ManifestReader                       $manifestReader
     * @param ManifestSyncService                  $syncService
     * @param Scheduler                            $scheduler
     * @param JobRunner                            $jobRunner
     * @param LockManager                          $lockManager
     * @param LoggerInterface                      $logger
     * @param string                               $manifestPath
     * @param string                               $lockDir
     * @param TimeZoneHelper                       $tz
     */
    public function __construct(
        private readonly DatabaseAdapterInterface            $db,
        private readonly DispatcherKillSwitchAdapterInterface $killSwitch,
        private readonly ManifestReader                      $manifestReader,
        private readonly ManifestSyncService                 $syncService,
        private readonly Scheduler                           $scheduler,
        private readonly JobRunner                           $jobRunner,
        private readonly LockManager                         $lockManager,
        private readonly LoggerInterface                     $logger,
        private readonly string                              $manifestPath,
        private readonly string                              $lockDir,
        private readonly TimeZoneHelper                      $tz,
    ) {}

    /**
     * Runs one dispatch tick: sync manifest if needed, then execute due jobs.
     *
     * Kill-switch disabled: manifest sync still runs, but job execution is fully
     * suspended (both scheduled and Run-Now jobs). Admin UI stays current.
     *
     * @return void
     */
    public function dispatch(): void
    {
        if (!$this->ensureLockDir()) {
            return;
        }

        // Manifest sync (mtime-driven, protected by sync flock).
        $classMap = $this->runSyncIfNeeded();

        if (!$this->killSwitch->get()) {
            $this->logger->info('CronAdmin: dispatcher disabled — skipping scheduled and Run-Now jobs.');
            return;
        }

        $now = new \DateTimeImmutable('now', $this->tz->displayTimezone());

        try {
            $jobs = $this->db->fetchAll(
                'SELECT * FROM cron_jobs WHERE active = 1 AND (enabled = 1 OR trigger_pending = 1)'
            );
        } catch (\Throwable $e) {
            $this->logger->error('CronAdmin: failed to load jobs: ' . $e->getMessage());
            return;
        }

        $this->jobRunner->resetTickState();

        foreach ($jobs as $job) {
            try {
                $jobKey = (string) $job['job_key'];
                $job['class_name'] = $classMap[$jobKey] ?? '';

                if ((int) $job['trigger_pending'] === 1) {
                    $this->handleManualTrigger($job, $now);
                } elseif ($this->scheduler->shouldRun($job, $now)) {
                    $this->jobRunner->run($job, false, true);
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    "CronAdmin: unhandled exception for job '{$job['job_key']}': " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Runs a single job unconditionally by job_key.
     *
     * Used by legacy shim scripts (cron/backup.php, etc.). Bypasses the
     * dispatcher kill switch — operator-initiated. When the cron_jobs table
     * is missing, loads the manifest and runs the task without DB persistence.
     *
     * @param string $jobKey
     * @return CronTaskResult
     */
    public function runByKey(string $jobKey): CronTaskResult
    {
        $this->ensureLockDir();

        // Load manifest for class resolution.
        try {
            $manifest = $this->manifestReader->load($this->manifestPath);
            $classMap = array_column($manifest, 'class', 'key');
        } catch (InvalidManifestException $e) {
            $this->logger->error('CronAdmin: manifest invalid in runByKey: ' . $e->getMessage());
            return CronTaskResult::failure('manifest_invalid', $e->getMessage());
        }

        if (!isset($classMap[$jobKey])) {
            $this->logger->error("CronAdmin: job_key '{$jobKey}' not found in manifest.");
            return CronTaskResult::failure('unknown_job_key', "Job '{$jobKey}' not declared in manifest.");
        }

        // Try DB row for full context (lock_timeout_seconds, etc.).
        $job = null;
        try {
            $job = $this->db->fetchOne('SELECT * FROM cron_jobs WHERE job_key = ?', [$jobKey]);
        } catch (\Throwable) {
            // Table may not exist yet — graceful degradation.
        }

        if ($job !== null) {
            $job['class_name'] = $classMap[$jobKey];
            return $this->jobRunner->run($job, false, false);
        }

        // No DB row: minimal synthetic job array, no persistence.
        $class      = $classMap[$jobKey];
        $syntheticJob = [
            'id'                  => 0,
            'job_key'             => $jobKey,
            'class_name'          => $class,
            'name_key'            => $jobKey,
            'lock_timeout_seconds'=> 3600,
            'log_to_db'           => 0,
            'email_report'        => 'off',
            'trigger_pending'     => 0,
            'trigger_pending_by'  => null,
            'last_status'         => null,
            'last_run_at'         => null,
        ];
        return $this->jobRunner->run($syntheticJob, false, false);
    }

    /**
     * Atomically claims a pending manual trigger, then executes the job.
     *
     * @param array<string, mixed> $job
     * @param \DateTimeImmutable   $now
     * @return void
     */
    private function handleManualTrigger(array $job, \DateTimeImmutable $now): void
    {
        // Atomic claim — prevents two overlapping ticks from both running it.
        $claimed = $this->db->execute(
            'UPDATE cron_jobs SET trigger_pending = 0 WHERE id = ? AND trigger_pending = 1',
            [(int) $job['id']]
        );

        if ($claimed !== 1) {
            return;
        }

        // Re-fetch to get updated row (trigger_pending_by, etc.).
        $fresh = $this->db->fetchOne('SELECT * FROM cron_jobs WHERE id = ?', [(int) $job['id']]);
        if ($fresh === null) {
            return;
        }
        $fresh['class_name'] = $job['class_name'];

        $this->jobRunner->run($fresh, true, true);
    }

    /**
     * Runs ManifestSyncService when the manifest file has changed since the last sync.
     *
     * Uses a sync flock so overlapping ticks that both detect a mtime change do
     * not produce duplicate sync_cron_manifest audit entries. Returns the classMap
     * from the successfully loaded manifest.
     *
     * On InvalidManifestException: logs ERROR, returns the classMap from the DB
     * (empty if the manifest was never valid) so the dispatch tick can still
     * service Run-Now requests for jobs that are already in the DB.
     *
     * @return array<string, string>  Map of job_key → task class name.
     */
    private function runSyncIfNeeded(): array
    {
        $currentMtime = @filemtime($this->manifestPath);
        if ($currentMtime === false) {
            $this->logger->error('CronAdmin: manifest file not found — ' . $this->manifestPath);
            return [];
        }

        $markerPath = $this->lockDir . '/' . self::MTIME_MARKER;
        $lastMtime  = is_file($markerPath) ? (int) trim((string) @file_get_contents($markerPath)) : 0;

        if ($currentMtime === $lastMtime) {
            // Manifest unchanged — load for classMap but skip the sync write.
            try {
                $manifest = $this->manifestReader->load($this->manifestPath);
                return array_column($manifest, 'class', 'key');
            } catch (InvalidManifestException $e) {
                $this->logger->error('CronAdmin: manifest invalid: ' . $e->getMessage());
                return [];
            }
        }

        // Manifest changed — acquire sync flock before writing.
        $syncFp = $this->lockManager->acquireSyncLock();
        if ($syncFp === null) {
            // Another tick is already syncing — skip this tick.
            $this->logger->info('CronAdmin: sync flock held by another tick — skipping sync.');
            try {
                $manifest = $this->manifestReader->load($this->manifestPath);
                return array_column($manifest, 'class', 'key');
            } catch (InvalidManifestException) {
                return [];
            }
        }

        try {
            $manifest = $this->manifestReader->load($this->manifestPath);
            $classMap = array_column($manifest, 'class', 'key');
            $this->syncService->sync($manifest, 'system', null);
            file_put_contents($markerPath, (string) $currentMtime);
            return $classMap;
        } catch (InvalidManifestException $e) {
            $this->logger->error('CronAdmin: manifest invalid, sync skipped: ' . $e->getMessage());
            return [];
        } catch (\Throwable $e) {
            $this->logger->error('CronAdmin: sync failed: ' . $e->getMessage());
            return [];
        } finally {
            $this->lockManager->releaseSyncLock($syncFp);
        }
    }

    /**
     * Creates the lock directory if it does not exist, failing loudly.
     *
     * On failure, marks all active enabled jobs as failed so the admin UI
     * reflects the outage immediately.
     *
     * @return bool  False when the directory could not be created.
     */
    private function ensureLockDir(): bool
    {
        if (is_dir($this->lockDir)) {
            return true;
        }

        if (!mkdir($this->lockDir, 0775, true) && !is_dir($this->lockDir)) {
            $this->logger->error("CronAdmin: lock directory unavailable: {$this->lockDir}");
            try {
                $this->db->execute(
                    "UPDATE cron_jobs
                     SET last_status = 'failure', last_error = 'lock_dir_unavailable', last_run_at = UTC_TIMESTAMP()
                     WHERE active = 1 AND (enabled = 1 OR trigger_pending = 1)"
                );
            } catch (\Throwable) {}
            return false;
        }

        return true;
    }
}
