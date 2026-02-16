<?php

declare(strict_types=1);

namespace PatchModule;

/**
 * MaintenanceMode - Flag-file-based maintenance mode toggle
 *
 * Creates a flag file that the host application's entry point checks very early
 * (before database initialization). Admin routes should remain accessible while
 * maintenance mode is active. The flag file contains JSON metadata (version,
 * language, timestamp) used by the maintenance view.
 *
 * @package PatchModule
 */
class MaintenanceMode
{
    /** @var string Absolute path to the maintenance flag file */
    private string $flagFile;

    /** @var string Default language for the maintenance page */
    private string $defaultLanguage;

    /**
     * @param string $tempPath Absolute path to the temp directory
     * @param string $defaultLanguage Default language code (e.g., 'hu', 'en')
     */
    public function __construct(string $tempPath, string $defaultLanguage = 'en')
    {
        $this->flagFile = rtrim($tempPath, '/') . '/.patch_maintenance';
        $this->defaultLanguage = $defaultLanguage;
    }

    /**
     * Enable maintenance mode for frontend users during patch installation
     *
     * @param string $version Version being installed
     * @param string|null $language Override language for the maintenance page
     * @return void
     */
    public function enable(string $version, ?string $language = null): void
    {
        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $data = json_encode([
            'version' => $version,
            'language' => $language ?? $this->defaultLanguage,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        file_put_contents($this->flagFile, $data);
    }

    /**
     * Disable maintenance mode after patch installation completes or fails
     *
     * @return void
     */
    public function disable(): void
    {
        if (file_exists($this->flagFile)) {
            @unlink($this->flagFile);
        }
    }

    /**
     * Check if maintenance mode is currently active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return file_exists($this->flagFile);
    }

    /**
     * Get maintenance mode metadata from the flag file
     *
     * @return array{version: string, language: string, started_at: string}|null
     */
    public function getFlagData(): ?array
    {
        if (!file_exists($this->flagFile)) {
            return null;
        }

        $content = file_get_contents($this->flagFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the absolute path to the flag file
     *
     * Useful for the host application's early maintenance mode check.
     *
     * @return string
     */
    public function getFlagFilePath(): string
    {
        return $this->flagFile;
    }
}