<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Reconciles the host's cron job manifest with the cron_jobs database table.
 */

declare(strict_types=1);

namespace CronAdmin;

use ActivityLogs\ActivityLogger;
use CronAdmin\Contracts\DatabaseAdapterInterface;
use CronAdmin\Contracts\LoggerInterface;

/**
 * Keeps the cron_jobs table in sync with the host's manifest file.
 *
 * Sync logic:
 * - Manifest entry not in DB     → INSERT with manifest defaults.
 * - Manifest entry already in DB → UPDATE name_key, description_key,
 *   lock_timeout_seconds, active=1. User edits (enabled, schedule, email_report,
 *   log_to_db) and runtime state (last_run_at, last_status, …) are preserved.
 * - DB row with active=1 not in manifest → soft-delete: active=0.
 *
 * Writes one ActivityLogger audit entry per invocation, only when there is a
 * non-empty diff (added, updated, or removed rows).
 */
class ManifestSyncService
{
    /**
     * @param DatabaseAdapterInterface $db
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        private readonly LoggerInterface          $logger,
    ) {}

    /**
     * Reconciles the manifest entries against the current cron_jobs table state.
     *
     * @param list<array<string, mixed>> $manifest  Validated entries from ManifestReader::load().
     * @param string                     $source    'admin' or 'system' — recorded in the audit log.
     * @param int|null                   $userId    Current user ID (null for system-triggered syncs).
     * @return void
     */
    public function sync(array $manifest, string $source = 'system', ?int $userId = null): void
    {
        $manifestKeys  = array_column($manifest, 'key');
        $manifestByKey = array_column($manifest, null, 'key');

        // Fetch ALL rows (active=0 included) so re-added jobs are detected as
        // UPDATE rather than INSERT (the UNIQUE constraint on job_key would
        // otherwise reject a re-insert of a soft-deleted row).
        $allDbRows    = $this->db->fetchAll('SELECT * FROM cron_jobs');
        $allDbByKey   = array_column($allDbRows, null, 'job_key');
        $activeDbRows = array_filter($allDbRows, static fn(array $r): bool => (int) $r['active'] === 1);
        $activeByKey  = array_column($activeDbRows, null, 'job_key');

        $added   = [];
        $updated = [];
        $removed = [];

        $this->db->withTransaction(function () use (
            $manifestKeys, $manifestByKey, $allDbByKey, $activeByKey,
            &$added, &$updated, &$removed, $userId
        ): void {
            // Insert new rows or update existing ones (including re-adds).
            foreach ($manifestKeys as $key) {
                $entry = $manifestByKey[$key];

                if (!isset($allDbByKey[$key])) {
                    // Completely new job — INSERT.
                    $this->insertJob($entry, $userId);
                    $added[] = $key;
                } else {
                    $existingRow = $allDbByKey[$key];
                    // Existing row (active or inactive) — UPDATE, set active=1.
                    $this->updateJob($entry, $existingRow, $userId);
                    if ((int) $existingRow['active'] === 0) {
                        // Counts as "added" in the audit log (re-activated).
                        $added[] = $key;
                    } elseif (
                        $existingRow['name_key'] !== $entry['name']
                        || $existingRow['description_key'] !== $entry['description']
                        || (int) $existingRow['lock_timeout_seconds'] !== (int) $entry['lock_timeout_seconds']
                    ) {
                        // Metadata actually changed — track as updated.
                        $updated[] = $key;
                    }
                    // else: no metadata change → omit from $updated to avoid spurious audit log
                }
            }

            // Soft-delete rows that are active=1 but no longer in the manifest.
            foreach ($activeByKey as $key => $row) {
                if (!in_array($key, $manifestKeys, true)) {
                    $this->db->execute(
                        'UPDATE cron_jobs SET active = 0, updated_at = NOW(), updated_by = ? WHERE id = ?',
                        [$userId, (int) $row['id']]
                    );
                    $removed[] = $key;
                }
            }
        });

        // One audit entry only when something actually changed.
        if (!empty($added) || !empty($updated) || !empty($removed)) {
            $this->logSync($added, $updated, $removed, $source, $userId);
        }
    }

    /**
     * Inserts a new cron_jobs row using manifest defaults.
     *
     * @param array<string, mixed> $entry
     * @param int|null             $userId
     * @return void
     */
    private function insertJob(array $entry, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO cron_jobs
                (job_key, name_key, description_key, frequency, every_n_minutes,
                 hour, minute, days_of_week, days_of_month,
                 email_report, log_to_db, enabled, active, lock_timeout_seconds,
                 updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [
                $entry['key'],
                $entry['name'],
                $entry['description'],
                $entry['default_frequency'],
                $entry['default_every_n_minutes'],
                $entry['default_hour'],
                $entry['default_minute'],
                $entry['default_days_of_week'],
                $entry['default_days_of_month'],
                $entry['default_email_report'],
                (int) $entry['default_log_to_db'],
                (int) $entry['default_enabled'],
                (int) $entry['lock_timeout_seconds'],
                $userId,
            ]
        );
    }

    /**
     * Updates mutable metadata on an existing row without touching user edits.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $row    Existing DB row.
     * @param int|null             $userId
     * @return void
     */
    private function updateJob(array $entry, array $row, ?int $userId): void
    {
        $this->db->execute(
            'UPDATE cron_jobs
             SET name_key          = ?,
                 description_key   = ?,
                 lock_timeout_seconds = ?,
                 active            = 1,
                 updated_at        = NOW(),
                 updated_by        = ?
             WHERE id = ?',
            [
                $entry['name'],
                $entry['description'],
                (int) $entry['lock_timeout_seconds'],
                $userId,
                (int) $row['id'],
            ]
        );
    }

    /**
     * Writes one ActivityLogger entry for the completed sync operation.
     *
     * @param list<string> $added
     * @param list<string> $updated
     * @param list<string> $removed
     * @param string       $source
     * @param int|null     $userId
     * @return void
     */
    private function logSync(
        array $added,
        array $updated,
        array $removed,
        string $source,
        ?int $userId
    ): void {
        try {
            // old_values carries what was removed; new_values carries what was added/updated.
            // Keeping updated in new_values only avoids ActivityLogger's filterUnchangedValues
            // stripping it when the same list would appear on both sides.
            ActivityLogger::log(
                $userId,
                'sync_cron_manifest',
                'cron_manifest',
                null,
                empty($removed) ? null : ['removed' => $removed],
                array_filter(['added' => $added, 'updated' => $updated], static fn($v): bool => !empty($v)),
                $source,
                null,
                null,
                null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('CronAdmin: failed to write sync audit log: ' . $e->getMessage());
        }
    }
}
