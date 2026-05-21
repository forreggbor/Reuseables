<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Converts a cron_jobs row into a human-readable schedule description.
 */

declare(strict_types=1);

namespace CronAdmin;

/**
 * Produces localised, human-readable schedule strings for display in the admin
 * UI and cron report emails.
 *
 * Calls the host's global __() function directly. The host MUST expose __() as
 * a global function that returns the unmodified key string when the key is not
 * found in the merged translation array (module fallback relies on this).
 */
class ScheduleFormatter
{
    /**
     * Returns a human-readable description of the job's schedule.
     *
     * Examples:
     *   every_n_minutes=360  → "Every 6 hours"
     *   every_n_minutes=5    → "Every 5 minutes"
     *   hourly m=30          → ":30 past every hour"
     *   daily h=3 m=30       → "Daily at 03:30"
     *   weekly days=0,3 h=23 m=59 → "Weekly: Sun, Wed at 23:59"
     *   monthly days=1,15 h=3 m=0 → "Monthly: 1, 15 at 03:00"
     *
     * @param array<string, mixed> $job  A cron_jobs row.
     * @return string
     */
    public static function summarize(array $job): string
    {
        $frequency = (string) ($job['frequency'] ?? '');

        switch ($frequency) {
            case 'every_n_minutes':
                $n = max(1, (int) ($job['every_n_minutes'] ?? 1));
                if ($n >= 60 && $n % 60 === 0) {
                    return __('TEXT_CRON_SCHEDULE_EVERY_N_HOURS', ['n' => $n / 60]);
                }
                return __('TEXT_CRON_SCHEDULE_EVERY_N_MINUTES', ['n' => $n]);

            case 'hourly':
                $minute = str_pad((string) (int) ($job['minute'] ?? 0), 2, '0', STR_PAD_LEFT);
                return __('TEXT_CRON_SCHEDULE_HOURLY_AT', ['minute' => $minute]);

            case 'daily':
                $time = self::formatTime($job);
                return __('TEXT_CRON_SCHEDULE_DAILY_AT', ['time' => $time]);

            case 'weekly':
                $time     = self::formatTime($job);
                $dayNums  = array_filter(
                    array_map('trim', explode(',', (string) ($job['days_of_week'] ?? ''))),
                    static fn(string $v): bool => $v !== ''
                );
                $dayNames = array_map([self::class, 'dayOfWeekName'], $dayNums);
                $days     = implode(', ', $dayNames);
                return __('TEXT_CRON_SCHEDULE_WEEKLY_AT', ['days' => $days, 'time' => $time]);

            case 'monthly':
                $time    = self::formatTime($job);
                $dayNums = array_filter(
                    array_map('trim', explode(',', (string) ($job['days_of_month'] ?? ''))),
                    static fn(string $v): bool => $v !== ''
                );
                $days    = implode(', ', $dayNums);
                return __('TEXT_CRON_SCHEDULE_MONTHLY_AT', ['days' => $days, 'time' => $time]);

            default:
                return $frequency;
        }
    }

    /**
     * Formats hour and minute as HH:MM.
     *
     * @param array<string, mixed> $job
     * @return string
     */
    private static function formatTime(array $job): string
    {
        $h = str_pad((string) (int) ($job['hour']   ?? 0), 2, '0', STR_PAD_LEFT);
        $m = str_pad((string) (int) ($job['minute'] ?? 0), 2, '0', STR_PAD_LEFT);
        return "{$h}:{$m}";
    }

    /**
     * Returns the abbreviated localised day name for a PHP date('w') value (0=Sun, 6=Sat).
     *
     * @param string $dow
     * @return string
     */
    private static function dayOfWeekName(string $dow): string
    {
        $map = [
            0 => 'TEXT_DAY_OF_WEEK_SUN',
            1 => 'TEXT_DAY_OF_WEEK_MON',
            2 => 'TEXT_DAY_OF_WEEK_TUE',
            3 => 'TEXT_DAY_OF_WEEK_WED',
            4 => 'TEXT_DAY_OF_WEEK_THU',
            5 => 'TEXT_DAY_OF_WEEK_FRI',
            6 => 'TEXT_DAY_OF_WEEK_SAT',
        ];

        $key = $map[(int) $dow] ?? null;
        return $key !== null ? __($key) : (string) $dow;
    }
}
