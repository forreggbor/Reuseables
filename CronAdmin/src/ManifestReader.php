<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Loads and validates the host's cron job manifest file.
 */

declare(strict_types=1);

namespace CronAdmin;

use CronAdmin\Exceptions\InvalidManifestException;
use CronAdmin\Tasks\CronTaskInterface;

/**
 * Loads cron/jobs.php and validates every declared job entry.
 *
 * A missing manifest file throws immediately (load-or-die). An empty manifest
 * (return []) is valid — all existing active rows will be soft-deleted.
 * All validation failures are collected and thrown together so the integrator
 * can fix every problem in one round-trip.
 */
class ManifestReader
{
    /** @var list<int> Allowed values for every_n_minutes. */
    private const VALID_EVERY_N = [1, 5, 10, 15, 20, 30, 60, 120, 180, 240, 360, 720, 1440];

    /** @var list<string> Allowed frequency values. */
    private const VALID_FREQUENCIES = ['every_n_minutes', 'hourly', 'daily', 'weekly', 'monthly'];

    /** @var list<string> Allowed email_report values. */
    private const VALID_EMAIL_REPORT = ['off', 'on_failure', 'every_run'];

    /** @var list<string> All recognised entry keys — unknown keys are rejected to catch typos. */
    private const ALLOWED_ENTRY_KEYS = [
        'key', 'class', 'name', 'description',
        'default_frequency', 'default_every_n_minutes',
        'default_hour', 'default_minute',
        'default_days_of_week', 'default_days_of_month',
        'default_enabled', 'default_email_report', 'default_log_to_db',
        'lock_timeout_seconds',
    ];

    /**
     * Loads the manifest file at $path and returns the validated entries.
     *
     * @param string $path  Absolute path to cron/jobs.php.
     * @return list<array<string, mixed>>  Validated manifest entries.
     * @throws InvalidManifestException  If the file is missing or any entry fails validation.
     */
    public function load(string $path): array
    {
        if (!file_exists($path)) {
            throw new InvalidManifestException(["Manifest file not found: {$path}"]);
        }

        $entries = require $path;

        if (!is_array($entries)) {
            throw new InvalidManifestException(['Manifest file must return an array.']);
        }

        if (empty($entries)) {
            return [];
        }

        $violations = [];
        $seenKeys   = [];

        foreach ($entries as $i => $entry) {
            $prefix = "Entry #{$i}";
            if (!is_array($entry)) {
                $violations[] = "{$prefix}: must be an array.";
                continue;
            }

            // ── unknown keys — catch typos before any field validation ────────
            $unknownKeys = array_diff(array_keys($entry), self::ALLOWED_ENTRY_KEYS);
            if (!empty($unknownKeys)) {
                $violations[] = "{$prefix}: unknown key(s): '" . implode("', '", $unknownKeys) . "'. Check for typos.";
            }

            // ── key ───────────────────────────────────────────────────────────
            $key = $entry['key'] ?? null;
            if (!is_string($key) || $key === '') {
                $violations[] = "{$prefix}: 'key' is required and must be a non-empty string.";
                $key = null;
            } elseif (!preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
                $violations[] = "{$prefix}: 'key' must match /^[a-z0-9_]{1,64}$/ (got '{$key}').";
                $key = null;
            } elseif (isset($seenKeys[$key])) {
                $violations[] = "{$prefix}: duplicate key '{$key}'.";
                $key = null;
            } else {
                $seenKeys[$key] = true;
            }

            // ── class ─────────────────────────────────────────────────────────
            $class = $entry['class'] ?? null;
            if (!is_string($class) || $class === '') {
                $violations[] = "{$prefix}: 'class' is required and must be a non-empty string.";
            } elseif (!class_exists($class, true)) {
                $violations[] = "{$prefix}: class '{$class}' does not exist.";
            } elseif (!is_a($class, CronTaskInterface::class, true)) {
                $violations[] = "{$prefix}: class '{$class}' must implement CronTaskInterface.";
            }

            // ── name / description ────────────────────────────────────────────
            foreach (['name', 'description'] as $field) {
                if (!isset($entry[$field]) || !is_string($entry[$field]) || $entry[$field] === '') {
                    $violations[] = "{$prefix}: '{$field}' is required and must be a non-empty string.";
                }
            }

            // ── default_frequency ─────────────────────────────────────────────
            $freq = $entry['default_frequency'] ?? null;
            if (!is_string($freq) || !in_array($freq, self::VALID_FREQUENCIES, true)) {
                $violations[] = "{$prefix}: 'default_frequency' must be one of: " . implode(', ', self::VALID_FREQUENCIES) . '.';
                $freq = null;
            }

            // ── frequency-specific conditional fields ─────────────────────────
            if ($freq !== null) {
                switch ($freq) {
                    case 'every_n_minutes':
                        $n = isset($entry['default_every_n_minutes']) ? (int) $entry['default_every_n_minutes'] : null;
                        if ($n === null || !in_array($n, self::VALID_EVERY_N, true)) {
                            $violations[] = "{$prefix}: 'default_every_n_minutes' must be one of: " . implode(', ', self::VALID_EVERY_N) . '.';
                        }
                        break;

                    case 'hourly':
                        $this->requireMinute($entry, $prefix, $violations);
                        break;

                    case 'daily':
                        $this->requireHourMinute($entry, $prefix, $violations);
                        break;

                    case 'weekly':
                        $this->requireHourMinute($entry, $prefix, $violations);
                        $dow = $entry['default_days_of_week'] ?? '';
                        if (!is_string($dow) || !preg_match('/^[0-6](,[0-6])*$/', $dow)) {
                            $violations[] = "{$prefix}: 'default_days_of_week' must be a CSV of digits 0–6 (e.g. \"0,3,5\").";
                        }
                        break;

                    case 'monthly':
                        $this->requireHourMinute($entry, $prefix, $violations);
                        $dom = $entry['default_days_of_month'] ?? '';
                        if (!is_string($dom) || !preg_match('/^([1-9]|[12]\d|3[01])(,([1-9]|[12]\d|3[01]))*$/', $dom)) {
                            $violations[] = "{$prefix}: 'default_days_of_month' must be a CSV of day numbers 1–31.";
                        }
                        break;
                }
            }

            // ── optional fields ───────────────────────────────────────────────
            if (isset($entry['default_enabled']) && !in_array((int) $entry['default_enabled'], [0, 1], true)) {
                $violations[] = "{$prefix}: 'default_enabled' must be 0 or 1.";
            }

            if (isset($entry['default_email_report']) && !in_array($entry['default_email_report'], self::VALID_EMAIL_REPORT, true)) {
                $violations[] = "{$prefix}: 'default_email_report' must be one of: " . implode(', ', self::VALID_EMAIL_REPORT) . '.';
            }

            if (isset($entry['default_log_to_db']) && !in_array((int) $entry['default_log_to_db'], [0, 1], true)) {
                $violations[] = "{$prefix}: 'default_log_to_db' must be 0 or 1.";
            }

            if (isset($entry['lock_timeout_seconds'])) {
                $lts = (int) $entry['lock_timeout_seconds'];
                if ($lts <= 0) {
                    $violations[] = "{$prefix}: 'lock_timeout_seconds' must be a positive integer.";
                }
            }
        }

        if (!empty($violations)) {
            throw new InvalidManifestException($violations);
        }

        return $this->normalise($entries);
    }

    /**
     * Normalises entries by filling optional-field defaults.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function normalise(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[] = array_merge([
                'default_enabled'         => 1,
                'default_hour'            => null,
                'default_minute'          => null,
                'default_days_of_week'    => '',
                'default_days_of_month'   => '',
                'default_every_n_minutes' => null,
                'default_email_report'    => 'off',
                'default_log_to_db'       => 0,
                'lock_timeout_seconds'    => 3600,
                'description'             => '',
            ], $entry);
        }
        return $out;
    }

    /**
     * Checks that default_hour (0–23) and default_minute (0–59) are present.
     *
     * @param array<string, mixed> $entry
     * @param string               $prefix
     * @param list<string>         $violations
     */
    private function requireHourMinute(array $entry, string $prefix, array &$violations): void
    {
        $h = isset($entry['default_hour']) ? (int) $entry['default_hour'] : null;
        $m = isset($entry['default_minute']) ? (int) $entry['default_minute'] : null;
        if ($h === null || $h < 0 || $h > 23) {
            $violations[] = "{$prefix}: 'default_hour' must be an integer 0–23.";
        }
        $this->requireMinute($entry, $prefix, $violations);
    }

    /**
     * Checks that default_minute (0–59) is present.
     *
     * @param array<string, mixed> $entry
     * @param string               $prefix
     * @param list<string>         $violations
     */
    private function requireMinute(array $entry, string $prefix, array &$violations): void
    {
        $m = isset($entry['default_minute']) ? (int) $entry['default_minute'] : null;
        if ($m === null || $m < 0 || $m > 59) {
            $violations[] = "{$prefix}: 'default_minute' must be an integer 0–59.";
        }
    }
}
