<?php

declare(strict_types=1);

namespace LicenseModule\Contracts;

/**
 * Session adapter interface for license module
 *
 * Abstracts session operations for framework independence.
 */
interface SessionAdapterInterface
{
    /**
     * Get a value from session
     *
     * @param string $key Session key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a value in session
     *
     * @param string $key Session key
     * @param mixed $value Value to store
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a key exists in session
     *
     * @param string $key Session key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a key from session
     *
     * @param string $key Session key
     * @return void
     */
    public function remove(string $key): void;
}
