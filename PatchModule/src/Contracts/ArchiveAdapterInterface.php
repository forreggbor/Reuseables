<?php

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Archive extraction adapter
 *
 * Abstracts .tar.gz extraction to support both shell exec and pure PHP fallback.
 *
 * @package PatchModule
 */
interface ArchiveAdapterInterface
{
    /**
     * Extract a .tar.gz archive to a destination directory
     *
     * @param string $archivePath Path to the .tgz/.tar.gz file
     * @param string $destDir Destination directory (must exist)
     * @return array{success: bool, error: ?string}
     */
    public function extract(string $archivePath, string $destDir): array;
}