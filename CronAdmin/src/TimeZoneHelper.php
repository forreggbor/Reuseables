<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * UTC-to-display-timezone conversion helper for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin;

/**
 * Converts UTC DATETIME strings to a configured display timezone and back.
 *
 * All DATETIME columns in cron_jobs are stored as UTC (via UTC_TIMESTAMP()).
 * This helper converts those values to the host's display timezone for UI
 * rendering and schedule matching, without touching the raw DB values.
 *
 * The two public conversion methods have deliberately different failure semantics:
 * - utcToDisplay()      → fail-soft (returns raw string on bad input; safe for display)
 * - parseUtcInDisplay() → fail-hard (throws on bad input; caller's try/catch decides policy)
 */
final class TimeZoneHelper
{
    private \DateTimeZone $utc;
    private \DateTimeZone $display;

    /**
     * @param string $displayTimezone  IANA timezone identifier (e.g. 'Europe/Budapest').
     * @throws \Exception  When $displayTimezone is not a valid IANA identifier.
     */
    public function __construct(string $displayTimezone)
    {
        $this->utc     = new \DateTimeZone('UTC');
        $this->display = new \DateTimeZone($displayTimezone);
    }

    /**
     * Converts a 'Y-m-d H:i:s' UTC string to a 'Y-m-d H:i:s' display-TZ string.
     *
     * Returns null for null or empty input. Returns the raw string on parse failure
     * so the UI always has something to show rather than crashing.
     *
     * @param string|null $utc  UTC DATETIME string from the DB.
     * @return string|null      Converted string in display TZ, or null, or raw passthrough.
     */
    public function utcToDisplay(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($utc, $this->utc))
                ->setTimezone($this->display)
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $utc;
        }
    }

    /**
     * Parses a UTC DATETIME string and returns a DateTimeImmutable in the display TZ.
     *
     * Throws on malformed input — callers must wrap in try/catch.
     * Use for date arithmetic (e.g. Scheduler DST guard), not for display.
     *
     * @param string $utc  UTC DATETIME string from the DB.
     * @return \DateTimeImmutable  In the display timezone.
     * @throws \Exception  On malformed input.
     */
    public function parseUtcInDisplay(string $utc): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($utc, $this->utc))->setTimezone($this->display);
    }

    /**
     * Returns the configured display timezone object.
     *
     * Used by Dispatcher to construct $now in the display timezone so that
     * Scheduler::shouldRun() compares schedule fields (hour/minute) in the
     * same timezone the admin used when configuring the job.
     *
     * @return \DateTimeZone
     */
    public function displayTimezone(): \DateTimeZone
    {
        return $this->display;
    }
}
