<?php

declare(strict_types=1);

namespace PatchModule\Adapters\Archive;

use PatchModule\Contracts\ArchiveAdapterInterface;

/**
 * Shell exec() based tar extraction adapter
 *
 * Uses the system tar command for .tar.gz extraction. Preferred on dedicated
 * servers where exec() is available (faster and more reliable than PharData).
 *
 * @package PatchModule
 */
class ExecTarAdapter implements ArchiveAdapterInterface
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

        $command = sprintf(
            'tar --no-same-owner --no-same-permissions -xzf %s -C %s 2>&1',
            escapeshellarg($archivePath),
            escapeshellarg($destDir)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'error' => 'tar extraction failed (exit code ' . $returnCode . '): ' . implode("\n", $output),
            ];
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * Check if the exec() function is available
     *
     * @return bool True if exec() can be used
     */
    public static function isAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled !== false && $disabled !== '') {
            $disabledFunctions = array_map('trim', explode(',', $disabled));
            if (in_array('exec', $disabledFunctions, true)) {
                return false;
            }
        }

        return true;
    }
}