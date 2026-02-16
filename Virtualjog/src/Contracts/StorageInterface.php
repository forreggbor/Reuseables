<?php

declare(strict_types=1);

namespace Virtualjog\Contracts;

/**
 * Key-value storage interface for persisting Virtualjog configuration data
 *
 * Used to store access token, client data, cookie module state, document cache,
 * and document type mappings. Implement this interface to integrate with your
 * framework's storage mechanism (database, file system, etc.).
 *
 * For production use with shared caching across users, a persistent adapter
 * (e.g., database-backed) is required. The default SessionStorage is only
 * suitable for development or single-user scenarios.
 */
interface StorageInterface
{
    /**
     * Retrieve a value from storage
     *
     * @param string $key   Storage key
     * @param mixed  $default Default value if key not found
     * @return mixed Stored value or default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store a value
     *
     * @param string $key   Storage key
     * @param mixed  $value Value to store (scalars, arrays, null)
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a key exists in storage
     *
     * @param string $key Storage key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a key from storage
     *
     * @param string $key Storage key
     * @return void
     */
    public function remove(string $key): void;
}
