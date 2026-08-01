<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Restore-maintenance flag seam — signals to the host's public front controller
 * that a destructive restore is in progress and normal traffic should see a
 * maintenance page instead of a half-restored application.
 */

namespace BackupRestore\Contracts;

/**
 * Enables/disables the restore-maintenance flag around a destructive restore.
 *
 * The flag is a simple on/off signal with metadata (e.g. start time, restore
 * type); how the host's public-facing front controller reads and displays it
 * is host code and out of scope for this module. The shipped default
 * (Adapters\Maintenance\FileMaintenanceGate) writes/removes a marker file.
 *
 * @package BackupRestore
 */
interface MaintenanceGateInterface
{
    /**
     * Enable the maintenance flag.
     *
     * @param array $meta Arbitrary metadata to persist alongside the flag
     *                     (e.g. ['type' => 'database', 'started_at' => ...])
     * @return bool True if the flag was written successfully
     */
    public function enable(array $meta = []): bool;

    /**
     * Disable (clear) the maintenance flag.
     *
     * Must be safe to call when the flag is already absent (idempotent).
     *
     * @return void
     */
    public function disable(): void;

    /**
     * Whether the maintenance flag is currently set.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Path where the flag is persisted (for host-side inspection/debugging).
     *
     * @return string
     */
    public function flagPath(): string;
}
