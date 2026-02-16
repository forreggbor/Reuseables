<?php

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Optional backup service adapter for pre-patch backup and rollback
 *
 * If not provided to PatchModule, backup/restore steps are gracefully skipped
 * and the module operates with file-snapshot-only rollback capability.
 *
 * @package PatchModule
 */
interface BackupAdapterInterface
{
    /**
     * Create a full backup before patch installation
     *
     * @param string $note Human-readable backup description
     * @param int|null $userId User who initiated the backup (null for system)
     * @return array{success: bool, backup_id: ?int, error: ?string}
     */
    public function createBackup(string $note, ?int $userId = null): array;

    /**
     * Restore database from a backup
     *
     * @param int $backupId Backup record ID
     * @return array{success: bool, error: ?string}
     */
    public function restoreDatabase(int $backupId): array;

    /**
     * Delete a backup completely (file + record)
     *
     * @param int $backupId Backup record ID
     * @return array{success: bool, error: ?string}
     */
    public function deleteBackup(int $backupId): array;

    /**
     * Get free disk space in bytes
     *
     * @return int Free bytes available on the storage partition
     */
    public function getFreeDiskSpace(): int;
}