<?php

declare(strict_types=1);

namespace LicenseModule\Adapters\Session;

use LicenseModule\Contracts\SessionAdapterInterface;

/**
 * Native PHP session adapter for license module
 *
 * Uses PHP's native $_SESSION superglobal.
 * Automatically starts session if not already started.
 */
class NativeSessionAdapter implements SessionAdapterInterface
{
    private string $prefix;

    /**
     * @param string $prefix Key prefix to avoid conflicts
     */
    public function __construct(string $prefix = 'license_')
    {
        $this->prefix = $prefix;
    }

    /**
     * Ensure session is started
     */
    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Get prefixed key
     */
    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureSession();
        $prefixedKey = $this->key($key);

        return $_SESSION[$prefixedKey] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureSession();
        $_SESSION[$this->key($key)] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        $this->ensureSession();

        return isset($_SESSION[$this->key($key)]);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        $this->ensureSession();
        unset($_SESSION[$this->key($key)]);
    }
}
