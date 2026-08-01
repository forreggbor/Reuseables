<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * File-marker implementation of MaintenanceGateInterface.
 */

namespace BackupRestore\Adapters\Maintenance;

use BackupRestore\Contracts\MaintenanceGateInterface;

/**
 * Writes/removes a JSON marker file to signal a restore-in-progress state.
 *
 * The host's public front controller is expected to check flagPath() and, if
 * present, serve a maintenance page instead of normal traffic — that
 * integration is host code and out of scope for this module.
 *
 * @package BackupRestore\Adapters\Maintenance
 */
class FileMaintenanceGate implements MaintenanceGateInterface
{
    private readonly string $flagPath;

    /**
     * @param string $flagPath Absolute path of the marker file (directory must be writable)
     */
    public function __construct(string $flagPath)
    {
        $this->flagPath = $flagPath;
    }

    /**
     * @inheritDoc
     */
    public function enable(array $meta = []): bool
    {
        $payload = json_encode(array_merge(['enabled_at' => date('c')], $meta), JSON_PRETTY_PRINT);
        if ($payload === false) {
            return false;
        }

        $dir = dirname($this->flagPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($this->flagPath, $payload, LOCK_EX) !== false;
    }

    /**
     * @inheritDoc
     */
    public function disable(): void
    {
        if (is_file($this->flagPath)) {
            @unlink($this->flagPath);
        }
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return is_file($this->flagPath);
    }

    /**
     * @inheritDoc
     */
    public function flagPath(): string
    {
        return $this->flagPath;
    }
}
