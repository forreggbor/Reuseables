<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Shared check that a backup directory is usable.
 */

namespace PatchModule\Adapters\Backup;

/**
 * Answers whether a backup directory exists and is writable, or can be created.
 *
 * Used by the health probes of both built-in backup adapters, which create their directory on first use.
 */
final class BackupDirectoryCheck
{
    /**
     * Describe why a backup directory cannot be used.
     *
     * @param string $dir Backup directory (it need not exist yet).
     *
     * @return string|null Reason it is unusable, or null when it is writable or can be created.
     */
    public static function problem(string $dir): ?string
    {
        if (is_dir($dir)) {
            return is_writable($dir) ? null : 'The backup directory is not writable: ' . $dir;
        }

        // Not there yet: the nearest existing ancestor must be writable so the adapter can create it.
        $ancestor = dirname($dir);
        while ($ancestor !== '' && !is_dir($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        if (!is_dir($ancestor) || !is_writable($ancestor)) {
            return 'The backup directory cannot be created: ' . $dir;
        }

        return null;
    }
}
