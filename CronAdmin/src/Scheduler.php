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
 * Scheduler assumes 1-minute crontab granularity (cron/run.php invoked every
 * minute via `* * * * *`). A 5-minute crontab cadence will silently miss jobs
 * scheduled between ticks.
 *
 * DST fall-back guard: for daily/weekly/monthly jobs, a same-day success entry
 * blocks the job from firing a second time during a fall-back hour repetition.
 * DST spring-forward: jobs scheduled at a skipped hour simply do not fire that
 * day — POSIX cron behaviour by design.
 *
 * All DATETIME columns (last_run_at) are stored as UTC. TimeZoneHelper converts
 * them to the display timezone for date comparison so the DST guard evaluates
 * dates in the timezone the admin used when scheduling the job.
 */
class Scheduler
{
    /**
     * @param TimeZoneHelper $tz  Used to parse last_run_at (UTC) in the display timezone.
     */
    public function __construct(private readonly TimeZoneHelper $tz) {}

    /**
     * Returns true when the job is due to run at $now.
     *
     * $now MUST be in the display timezone — Dispatcher constructs it via
     * TimeZoneHelper::displayTimezone() so schedule fields (hour/minute) are
     * evaluated in the same timezone the admin configured.
     *
     * @param array<string, mixed> $job  A cron_jobs row (includes last_status, last_run_at).
     * @param \DateTimeImmutable   $now  Frozen timestamp in the display timezone.
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
        // last_run_at is stored as UTC; parseUtcInDisplay() converts to display TZ before
        // comparing dates so the guard evaluates "same day" in the admin's timezone.
        if (in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            if (($job['last_status'] ?? null) === 'success' && ($job['last_run_at'] ?? null) !== null) {
                try {
                    $lastDate = $this->tz
                        ->parseUtcInDisplay((string) $job['last_run_at'])
                        ->format('Y-m-d');
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
