<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * ProfileService — reusable backup-profile CRUD, scheduling, and retention.
 */

namespace BackupRestore;

use PDO;

/**
 * Manages backup profiles: CRUD operations, scheduling logic, next-run
 * calculation, and retention cleanup. Profiles define reusable backup
 * configurations with folder selection, scheduling, and remote-server
 * assignment.
 *
 * @package BackupRestore
 */
final class ProfileService
{
    /**
     * Minimum number of completed backups kept per profile regardless of age.
     * Pure age-based retention with a very short retention_days (or a schedule
     * that has not produced a newer backup yet) could otherwise prune every
     * single backup for a profile, leaving nothing to restore from.
     */
    private const int MIN_KEPT_COMPLETED_BACKUPS = 1;

    /**
     * @param PDO $pdo Bookkeeping connection
     * @param BackupEngine $backupEngine Used to execute scheduled backups and prune retained files
     * @param array{backups:string,backup_profiles:string,backup_remote_servers:string} $tableNames
     * @param callable(string,string):void $logger
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly BackupEngine $backupEngine,
        private readonly array $tableNames,
        private $logger,
    ) {
    }

    /**
     * Get all backup profiles.
     *
     * @return array<int,object> List of profile objects
     */
    public function getAll(): array
    {
        $profilesTable = $this->tableNames['backup_profiles'];
        $remoteTable = $this->tableNames['backup_remote_servers'];

        $stmt = $this->pdo->query(
            "SELECT bp.*, brs.name AS remote_server_name
             FROM {$profilesTable} bp
             LEFT JOIN {$remoteTable} brs ON bp.remote_server_id = brs.id
             ORDER BY bp.name ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Get a single profile by ID.
     *
     * @param int $id Profile ID
     * @return object|null Profile record or null if not found
     */
    public function getById(int $id): ?object
    {
        $profilesTable = $this->tableNames['backup_profiles'];
        $remoteTable = $this->tableNames['backup_remote_servers'];

        $stmt = $this->pdo->prepare(
            "SELECT bp.*, brs.name AS remote_server_name
             FROM {$profilesTable} bp
             LEFT JOIN {$remoteTable} brs ON bp.remote_server_id = brs.id
             WHERE bp.id = :id"
        );
        $stmt->execute([':id' => $id]);

        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * Create a new backup profile.
     *
     * @param array $data Profile data
     * @return array{success: bool, id: ?int, error: ?string}
     */
    public function create(array $data): array
    {
        $profilesTable = $this->tableNames['backup_profiles'];

        try {
            $nextRunAt = null;
            if (!empty($data['schedule_enabled']) && !empty($data['schedule_type'])) {
                $nextRunAt = $this->calculateNextRun($data);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO {$profilesTable}
                 (name, description, type, include_database, included_paths, excluded_paths,
                  remote_server_id, schedule_enabled, schedule_type, schedule_time, schedule_day,
                  retention_days, is_active, next_run_at, created_at)
                 VALUES
                 (:name, :description, :type, :include_database, :included_paths, :excluded_paths,
                  :remote_server_id, :schedule_enabled, :schedule_type, :schedule_time, :schedule_day,
                  :retention_days, :is_active, :next_run_at, NOW())"
            );

            $stmt->execute([
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':type' => $data['type'] ?? 'full',
                ':include_database' => $data['include_database'] ?? 1,
                ':included_paths' => $data['included_paths'] ?? null,
                ':excluded_paths' => $data['excluded_paths'] ?? null,
                ':remote_server_id' => $data['remote_server_id'] ?? null,
                ':schedule_enabled' => $data['schedule_enabled'] ?? 0,
                ':schedule_type' => $data['schedule_type'] ?? null,
                ':schedule_time' => $data['schedule_time'] ?? null,
                ':schedule_day' => isset($data['schedule_day']) ? (int) $data['schedule_day'] : null,
                ':retention_days' => $data['retention_days'] ?? 30,
                ':is_active' => $data['is_active'] ?? 1,
                ':next_run_at' => $nextRunAt,
            ]);

            return ['success' => true, 'id' => (int) $this->pdo->lastInsertId(), 'error' => null];
        } catch (\Throwable $e) {
            $this->log('Failed to create backup profile: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update an existing backup profile.
     *
     * @param int $id Profile ID
     * @param array $data Updated profile data
     * @return array{success: bool, error: ?string}
     */
    public function update(int $id, array $data): array
    {
        $profilesTable = $this->tableNames['backup_profiles'];

        $profile = $this->getById($id);
        if (!$profile) {
            return ['success' => false, 'error' => 'Profile not found'];
        }

        try {
            $nextRunAt = null;
            if (!empty($data['schedule_enabled']) && !empty($data['schedule_type'])) {
                $nextRunAt = $this->calculateNextRun($data);
            }

            $stmt = $this->pdo->prepare(
                "UPDATE {$profilesTable} SET
                 name = :name, description = :description, type = :type,
                 include_database = :include_database, included_paths = :included_paths,
                 excluded_paths = :excluded_paths, remote_server_id = :remote_server_id,
                 schedule_enabled = :schedule_enabled, schedule_type = :schedule_type,
                 schedule_time = :schedule_time, schedule_day = :schedule_day,
                 retention_days = :retention_days, is_active = :is_active,
                 next_run_at = :next_run_at, updated_at = NOW()
                 WHERE id = :id"
            );

            $stmt->execute([
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':type' => $data['type'] ?? 'full',
                ':include_database' => $data['include_database'] ?? 1,
                ':included_paths' => $data['included_paths'] ?? null,
                ':excluded_paths' => $data['excluded_paths'] ?? null,
                ':remote_server_id' => $data['remote_server_id'] ?? null,
                ':schedule_enabled' => $data['schedule_enabled'] ?? 0,
                ':schedule_type' => $data['schedule_type'] ?? null,
                ':schedule_time' => $data['schedule_time'] ?? null,
                ':schedule_day' => isset($data['schedule_day']) ? (int) $data['schedule_day'] : null,
                ':retention_days' => $data['retention_days'] ?? 30,
                ':is_active' => $data['is_active'] ?? 1,
                ':next_run_at' => $nextRunAt,
                ':id' => $id,
            ]);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log('Failed to update backup profile: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a backup profile.
     *
     * @param int $id Profile ID
     * @return array{success: bool, error: ?string}
     */
    public function delete(int $id): array
    {
        $profilesTable = $this->tableNames['backup_profiles'];

        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$profilesTable} WHERE id = :id");
            $stmt->execute([':id' => $id]);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->log('Failed to delete backup profile: ' . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get profiles that are scheduled and due for execution.
     *
     * Returns active profiles where schedule is enabled and next_run_at <= NOW().
     *
     * @return array<int,object> List of profile objects due for execution
     */
    public function getScheduledProfiles(): array
    {
        $profilesTable = $this->tableNames['backup_profiles'];
        $remoteTable = $this->tableNames['backup_remote_servers'];

        $stmt = $this->pdo->query(
            "SELECT bp.*, brs.name AS remote_server_name
             FROM {$profilesTable} bp
             LEFT JOIN {$remoteTable} brs ON bp.remote_server_id = brs.id
             WHERE bp.is_active = 1
               AND bp.schedule_enabled = 1
               AND bp.next_run_at IS NOT NULL
               AND bp.next_run_at <= NOW()
             ORDER BY bp.next_run_at ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * Calculate the next run datetime based on schedule configuration.
     *
     * @param array|object $profile Profile data (array or object)
     * @return string|null Datetime string or null if scheduling is disabled
     */
    public function calculateNextRun(array|object $profile): ?string
    {
        $scheduleType = is_object($profile) ? $profile->schedule_type : ($profile['schedule_type'] ?? null);
        $scheduleTime = is_object($profile) ? $profile->schedule_time : ($profile['schedule_time'] ?? '02:00:00');
        $scheduleDay = is_object($profile) ? $profile->schedule_day : ($profile['schedule_day'] ?? null);
        $scheduleEnabled = is_object($profile) ? $profile->schedule_enabled : ($profile['schedule_enabled'] ?? 0);

        if (!$scheduleEnabled || !$scheduleType) {
            return null;
        }

        if ($scheduleTime && strlen($scheduleTime) === 5) {
            $scheduleTime .= ':00';
        }

        $now = new \DateTimeImmutable();
        $time = $scheduleTime ?: '02:00:00';

        // DateTimeImmutable's constructor throws on a malformed relative-format
        // string — a corrupt/unexpected schedule_time or schedule_day value in
        // the DB must not crash create()/update() or the cron-invoked
        // executeProfile(); fail safe by disabling the schedule instead.
        try {
            switch ($scheduleType) {
                case 'daily':
                    $next = new \DateTimeImmutable('today ' . $time);
                    if ($next <= $now) {
                        $next = $next->modify('+1 day');
                    }
                    return $next->format('Y-m-d H:i:s');

                case 'weekly':
                    $dayOfWeek = (int) ($scheduleDay ?? 0); // 0=Sunday, 1=Monday, ..., 6=Saturday
                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $dayName = $days[$dayOfWeek] ?? 'Sunday';

                    $next = new \DateTimeImmutable("next {$dayName} {$time}");
                    $todayTarget = new \DateTimeImmutable("this {$dayName} {$time}");
                    if ($todayTarget > $now) {
                        $next = $todayTarget;
                    }
                    return $next->format('Y-m-d H:i:s');

                case 'monthly':
                    $dayOfMonth = min(28, max(1, (int) ($scheduleDay ?? 1)));
                    $nextMonth = new \DateTimeImmutable(date('Y-m-') . str_pad((string) $dayOfMonth, 2, '0', STR_PAD_LEFT) . ' ' . $time);
                    if ($nextMonth <= $now) {
                        $nextMonth = $nextMonth->modify('+1 month');
                    }
                    return $nextMonth->format('Y-m-d H:i:s');

                default:
                    return null;
            }
        } catch (\Throwable $e) {
            $this->log("calculateNextRun failed for schedule_type={$scheduleType}, schedule_time={$time}: " . $e->getMessage() . ' — schedule disabled', 'WARNING');
            return null;
        }
    }

    /**
     * Execute a backup based on a profile configuration.
     *
     * Creates a backup using the profile's settings (type, included/excluded
     * paths) and updates the profile's last_run_at and next_run_at timestamps.
     *
     * @param int $id Profile ID to execute
     * @return array{success: bool, backup_id: ?int, error: ?string}
     */
    public function executeProfile(int $id): array
    {
        $profile = $this->getById($id);
        if (!$profile) {
            return ['success' => false, 'backup_id' => null, 'error' => 'Profile not found'];
        }

        $includedPaths = null;
        if ($profile->included_paths) {
            $includedPaths = json_decode($profile->included_paths, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Profile #{$id}: included_paths is not valid JSON (" . json_last_error_msg() . ') — treating as unrestricted', 'WARNING');
                $includedPaths = null;
            }
        }

        $excludedPaths = null;
        if ($profile->excluded_paths) {
            $excludedPaths = json_decode($profile->excluded_paths, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log("Profile #{$id}: excluded_paths is not valid JSON (" . json_last_error_msg() . ') — treating as unrestricted', 'WARNING');
                $excludedPaths = null;
            }
        }

        $result = $this->backupEngine->createBackup([
            'type' => $profile->type,
            'note' => 'Scheduled backup: ' . $profile->name,
            'included_paths' => $includedPaths,
            'excluded_paths' => $excludedPaths,
            'profile_id' => $id,
            'created_by' => null, // System/scheduled
        ]);

        // Update profile timestamps and last-run outcome — last_status/last_error
        // make a persistently failing schedule visible on the profile itself
        // instead of only in application logs.
        $profilesTable = $this->tableNames['backup_profiles'];
        $nextRunAt = $this->calculateNextRun($profile);

        $stmt = $this->pdo->prepare(
            "UPDATE {$profilesTable} SET
             last_run_at = NOW(),
             last_status = :last_status,
             last_error = :last_error,
             next_run_at = :next_run_at,
             updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':last_status' => $result['success'] ? 'success' : 'failure',
            ':last_error' => $result['success'] ? null : ($result['error'] ?? 'Unknown error'),
            ':next_run_at' => $nextRunAt,
            ':id' => $id,
        ]);

        return [
            'success' => $result['success'],
            'backup_id' => $result['backup_id'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Clean up old backups based on a profile's retention_days setting.
     *
     * Deletes backup files (keeping history) older than the profile's
     * retention period, always keeping at least {@see MIN_KEPT_COMPLETED_BACKUPS}.
     *
     * @param int $profileId Profile ID
     * @return int Number of backups cleaned up
     */
    public function cleanupByRetention(int $profileId): int
    {
        $profile = $this->getById($profileId);
        if (!$profile || !$profile->retention_days) {
            return 0;
        }

        return $this->cleanupOlderThan(
            (int) $profile->retention_days,
            "profile_id = :scope_id",
            [':scope_id' => $profileId]
        );
    }

    /**
     * Clean up old MANUAL (ad-hoc, profile_id IS NULL) backups.
     *
     * Manual backups are never touched by {@see cleanupByRetention()} (scoped
     * to a single profile) or by any other automatic path, so left alone they
     * accumulate forever. This is deliberately opt-in — call only when a host
     * explicitly configures a positive retention for manual backups.
     *
     * Applies the same {@see MIN_KEPT_COMPLETED_BACKUPS} floor as
     * cleanupByRetention() — the newest manual backup(s) are always kept
     * regardless of age.
     *
     * @param int $retentionDays Delete completed manual backups older than this
     * @return int Number of backups cleaned up
     */
    public function cleanupManualBackups(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return $this->cleanupOlderThan($retentionDays, "profile_id IS NULL", []);
    }

    /**
     * Shared retention-cleanup implementation for {@see cleanupByRetention()}
     * and {@see cleanupManualBackups()}.
     *
     * @param int $retentionDays
     * @param string $scopeWhere Extra WHERE clause (profile scope)
     * @param array $scopeParams Bound params for $scopeWhere
     * @return int Number of backups cleaned up
     */
    private function cleanupOlderThan(int $retentionDays, string $scopeWhere, array $scopeParams): int
    {
        $backupsTable = $this->tableNames['backups'];
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        // Age-eligible candidates, newest first, so the MIN_KEPT_COMPLETED_BACKUPS
        // floor below always protects the MOST RECENT backups.
        $stmt = $this->pdo->prepare(
            "SELECT id FROM {$backupsTable}
             WHERE {$scopeWhere}
               AND status = 'completed'
               AND created_at < :cutoff
               AND file_deleted_at IS NULL
             ORDER BY created_at DESC"
        );
        $stmt->execute([...$scopeParams, ':cutoff' => $cutoff]);
        $candidates = $stmt->fetchAll(PDO::FETCH_OBJ);

        // How many completed, non-deleted backups exist in this scope in total
        // (including ones newer than the cutoff) — the floor is a count of
        // backups kept, not a count of candidates skipped.
        $totalStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$backupsTable} WHERE {$scopeWhere} AND status = 'completed' AND file_deleted_at IS NULL"
        );
        $totalStmt->execute($scopeParams);
        $totalCompleted = (int) $totalStmt->fetchColumn();

        // Never prune below the floor: skip the newest `keepFloor` age-eligible
        // candidates (they're already the most-recent ones thanks to the DESC order).
        $keepFloor = max(0, self::MIN_KEPT_COMPLETED_BACKUPS - ($totalCompleted - count($candidates)));
        if ($keepFloor > 0) {
            $candidates = array_slice($candidates, $keepFloor);
        }

        $count = 0;
        foreach ($candidates as $row) {
            $result = $this->backupEngine->deleteBackupFile((int) $row->id);
            if ($result['success']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param string $message
     * @param string $level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        try {
            ($this->logger)($message, $level);
        } catch (\Throwable) {
            // A broken host logger must never break a backup/restore operation.
        }
    }
}
