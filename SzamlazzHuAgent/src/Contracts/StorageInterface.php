<?php

declare(strict_types=1);

namespace SzamlazzHuAgent\Contracts;

/**
 * Storage interface for invoice PDF files
 */
interface StorageInterface
{
    /**
     * Save PDF content to storage
     *
     * @param string $filename Filename (without path)
     * @param string $content PDF content (binary)
     * @return string Full path to saved file
     */
    public function save(string $filename, string $content): string;

    /**
     * Get full path for a filename
     *
     * @param string $filename Filename
     * @return string Full path
     */
    public function getPath(string $filename): string;

    /**
     * Check if file exists
     *
     * @param string $filename Filename
     * @return bool
     */
    public function exists(string $filename): bool;

    /**
     * Get file content
     *
     * @param string $filename Filename
     * @return string|null File content or null if not found
     */
    public function get(string $filename): ?string;

    /**
     * Delete a file
     *
     * @param string $filename Filename
     * @return bool Success status
     */
    public function delete(string $filename): bool;
}
