<?php

declare(strict_types=1);

namespace PatchModule\Adapters\Archive;

use PatchModule\Contracts\ArchiveAdapterInterface;

/**
 * Pure PHP PharData-based tar extraction adapter
 *
 * Fallback for shared hosting environments where exec() is not available.
 * Uses PHP's built-in PharData class for .tar.gz extraction.
 *
 * @package PatchModule
 */
class PharTarAdapter implements ArchiveAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function extract(string $archivePath, string $destDir): array
    {
        if (!file_exists($archivePath)) {
            return ['success' => false, 'error' => 'Archive file not found: ' . $archivePath];
        }

        if (!is_dir($destDir)) {
            return ['success' => false, 'error' => 'Destination directory not found: ' . $destDir];
        }

        try {
            $phar = new \PharData($archivePath);

            // If it's a .tgz/.tar.gz, decompress first then extract
            if ($phar->isCompressed()) {
                $phar->decompress();

                // PharData::decompress() creates a .tar file alongside the .tgz
                $tarPath = preg_replace('/\.(tgz|tar\.gz)$/i', '.tar', $archivePath);
                if ($tarPath !== $archivePath && file_exists($tarPath)) {
                    $phar = new \PharData($tarPath);
                    $phar->extractTo($destDir, null, true);
                    @unlink($tarPath);
                } else {
                    $phar->extractTo($destDir, null, true);
                }
            } else {
                $phar->extractTo($destDir, null, true);
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'PharData extraction failed: ' . $e->getMessage()];
        }
    }
}