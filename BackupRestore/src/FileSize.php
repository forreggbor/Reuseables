<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Human-readable byte-size formatting — a pure, stateless helper shared by
 * BackupEngine, RemoteService, and the views.
 */

namespace BackupRestore;

/**
 * Formats byte counts as human-readable strings (e.g. "1.5 GB").
 *
 * Locale-agnostic by design (period decimal separator, no thousands
 * grouping) — a host wanting locale-aware formatting reformats the
 * underlying byte count in its own view layer.
 *
 * @package BackupRestore
 */
final class FileSize
{
    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Format a byte count as a human-readable string.
     *
     * @param int $bytes Size in bytes
     * @param int $decimals Decimal places for units above bytes (default 2)
     * @return string e.g. "512 B", "1.46 MB", "2.30 GB"
     */
    public static function format(int $bytes, int $decimals = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        $count = count($units);

        while ($value >= 1024 && $i < $count - 1) {
            $value /= 1024;
            $i++;
        }

        $formatted = $i === 0 ? (string) (int) $value : number_format($value, $decimals, '.', '');

        return $formatted . ' ' . $units[$i];
    }
}
