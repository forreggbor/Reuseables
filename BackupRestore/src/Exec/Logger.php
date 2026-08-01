<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Static logging seam for the Exec layer — ShellHelper/PhpHelper are pure
 * static utility classes (mirroring the ported JupitERP originals) and have
 * no per-request instance the facade could inject a logger callable into.
 * BackupRestore::__construct() configures this once, matching the same
 * "static init()" pattern ActivityLogs\ActivityLogger uses.
 */

namespace BackupRestore\Exec;

/**
 * Process-wide logger seam for the Exec layer's static utility classes.
 *
 * @package BackupRestore\Exec
 */
final class Logger
{
    /** @var callable(string, string): void */
    private static $logger;

    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Configure the logger callable. Defaults to a no-op when never called.
     *
     * @param callable(string, string): void $logger
     * @return void
     */
    public static function configure(callable $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * @param string $message
     * @param string $level ERROR|WARNING|INFO|DEBUG
     * @return void
     */
    public static function log(string $message, string $level = 'INFO'): void
    {
        if (self::$logger === null) {
            return;
        }

        try {
            (self::$logger)($message, $level);
        } catch (\Throwable) {
            // A broken host logger must never break a backup/restore operation.
        }
    }
}
