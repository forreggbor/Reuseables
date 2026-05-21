<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Schedule-matching logic for cron job execution decisions.
 */

declare(strict_types=1);

namespace CronAdmin;

/**
 * Determines whether a cron job should run at the given moment.
 *
 * Uses PHP's default timezone (set via date.timezone or date_default_timezone_set
 * in the host bootstrap) — no timezone config key on the module.
 *
 * Scheduler assumes 1-minute crontab granularity (cron/run.php invoked every
 * minute via `* * * * *`). A 5-minute crontab cadence will silently miss jobs
 * scheduled between ticks.
 *
 * DST fall-back guard: for daily/weekly/monthly jobs, a same-day success entry
 * blocks the job from firing a second time during a fall-back hour repetition.
 * DST spring-forward: jobs scheduled at a skipped hour simply do not fire that
 * day — POSIX cron behaviour by design.
 */
class Scheduler
{
    /**
     * Returns true when the job is due to run at $now.
     *
     * $now MUST be in the host display timezone (the one set via date_default_timezone_set
     * or date.timezone). The DST fall-back guard parses last_run_at as a DATETIME string
     * using PHP's default timezone; if the DB session timezone differs, the guard may
     * misfire during DST transitions.
     *
     * @param array<string, mixed> $job  A cron_jobs row (includes last_status, last_run_at).
     * @param \DateTimeImmutable   $now  Frozen timestamp in the host display timezone.
     * @return bool
     */
    public function shouldRun(array $job, \DateTimeImmutable $now): bool
    {
        $m   = (int) $now->format('i');
        $h   = (int) $now->format('G');
        $dow = (int) $now->format('w'); // 0=Sunday, 6=Saturday
        $dom = (int) $now->format('j'); // 1–31

        $frequency = (string) ($job['frequency'] ?? '');
        $matched   = false;

        switch ($frequency) {
            case 'every_n_minutes':
                $n           = max(1, (int) ($job['every_n_minutes'] ?? 1));
                $minuteOfDay = $h * 60 + $m;
                $matched     = ($minuteOfDay % $n === 0);
                break;

            case 'hourly':
                $matched = ($m === (int) ($job['minute'] ?? 0));
                break;

            case 'daily':
                $matched = ($h === (int) ($job['hour'] ?? 0) && $m === (int) ($job['minute'] ?? 0));
                break;

            case 'weekly':
                if ($h === (int) ($job['hour'] ?? 0) && $m === (int) ($job['minute'] ?? 0)) {
                    // array_filter without callback drops "0" (Sunday) — use explicit check.
                    $days    = array_filter(
                        array_map('trim', explode(',', (string) ($job['days_of_week'] ?? ''))),
                        static fn(string $v): bool => $v !== ''
                    );
                    $matched = in_array((string) $dow, $days, true);
                }
                break;

            case 'monthly':
                if ($h === (int) ($job['hour'] ?? 0) && $m === (int) ($job['minute'] ?? 0)) {
                    $days    = array_filter(
                        array_map('trim', explode(',', (string) ($job['days_of_month'] ?? ''))),
                        static fn(string $v): bool => $v !== ''
                    );
                    $matched = in_array((string) $dom, $days, true);
                }
                break;
        }

        if (!$matched) {
            return false;
        }

        // DST fall-back guard: prevent same-day double-fire for date-based frequencies.
        // Not applied to every_n_minutes or hourly (minute-level, not date-level semantics).
        if (in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            if (($job['last_status'] ?? null) === 'success' && ($job['last_run_at'] ?? null) !== null) {
                try {
                    $lastDate = (new \DateTimeImmutable((string) $job['last_run_at']))->format('Y-m-d');
                    if ($lastDate === $now->format('Y-m-d')) {
                        return false;
                    }
                } catch (\Throwable) {
                    // Unparseable last_run_at — skip the guard; run.
                }
            }
        }

        return true;
    }
}
