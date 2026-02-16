<?php

declare(strict_types=1);

namespace Virtualjog\Adapters;

use Virtualjog\Contracts\StorageInterface;

/**
 * Session-based storage adapter for Virtualjog
 *
 * Uses PHP's native $_SESSION superglobal for key-value persistence.
 * Automatically starts a session if one is not already active.
 *
 * WARNING: This adapter stores data per-session and per-user. It is NOT suitable
 * for shared caching across users (e.g., document list cache). For production use,
 * implement a persistent adapter (database, file system, etc.).
 */
class SessionStorage implements StorageInterface
{
    /** @var string Key prefix to namespace session keys and avoid collisions */
    private string $prefix;

    /**
     * Initialize session storage adapter
     *
     * @param string $prefix Key prefix for session keys (default: 'vjog_')
     */
    public function __construct(string $prefix = 'vjog_')
    {
        $this->prefix = $prefix;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureSession();
        $prefixedKey = $this->prefix . $key;

        return $_SESSION[$prefixedKey] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureSession();
        $_SESSION[$this->prefix . $key] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        $this->ensureSession();

        return isset($_SESSION[$this->prefix . $key]);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        $this->ensureSession();
        unset($_SESSION[$this->prefix . $key]);
    }

    /**
     * Ensure a PHP session is started
     *
     * @return void
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
