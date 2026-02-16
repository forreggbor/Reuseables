<?php

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Optional logger interface for error and activity logging
 *
 * If not provided to PatchModule, all logging is silently skipped.
 * Combines application logging and activity audit in one interface.
 *
 * @package PatchModule
 */
interface LoggerInterface
{
    /**
     * Log an application message
     *
     * @param string $message Log message
     * @param string $level Log level: ERROR, WARNING, INFO, DEBUG
     * @return void
     */
    public function log(string $message, string $level = 'INFO'): void;

    /**
     * Log an activity audit entry (data change tracking)
     *
     * @param string $action Action identifier (e.g., 'install_patch', 'dismiss_patch')
     * @param string $entityType Entity type (e.g., 'patch')
     * @param int|null $entityId Entity ID (e.g., patch_history record ID)
     * @param array|null $oldValues Previous state
     * @param array|null $newValues New state
     * @param int|null $userId User who performed the action
     * @return void
     */
    public function activity(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId = null
    ): void;
}