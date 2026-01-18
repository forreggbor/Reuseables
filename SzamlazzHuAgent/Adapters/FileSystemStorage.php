<?php

declare(strict_types=1);

namespace SzamlazzHuAgent\Adapters;

use SzamlazzHuAgent\Contracts\StorageInterface;

/**
 * File system storage adapter for invoice PDFs
 */
class FileSystemStorage implements StorageInterface
{
    private string $basePath;

    /**
     * @param string $basePath Base directory for storing files
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        // Create directory if it doesn't exist
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function save(string $filename, string $content): string
    {
        $path = $this->getPath($filename);
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath(string $filename): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $filename): bool
    {
        return file_exists($this->getPath($filename));
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $filename): ?string
    {
        $path = $this->getPath($filename);

        if (!file_exists($path)) {
            return null;
        }

        return file_get_contents($path);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $filename): bool
    {
        $path = $this->getPath($filename);

        if (!file_exists($path)) {
            return true;
        }

        return unlink($path);
    }

    /**
     * Get the base path
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
