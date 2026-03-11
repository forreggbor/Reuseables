<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * In-memory session adapter for CLI contexts where session_start() is not available.
 */

declare(strict_types=1);

namespace LicenseModule\Adapters\Session;

use LicenseModule\Contracts\SessionAdapterInterface;

/**
 * In-memory session adapter for the license module.
 *
 * Stores values in a plain PHP array — no session_start(), no $_SESSION.
 * Intended for CLI / cron contexts where headers cannot be sent.
 */
class MemorySessionAdapter implements SessionAdapterInterface
{
    /** @var array<string, mixed> In-memory store. */
    private array $data = [];

    private string $prefix;

    /**
     * @param string $prefix Key prefix to avoid conflicts with other stores.
     */
    public function __construct(string $prefix = 'license_')
    {
        $this->prefix = $prefix;
    }

    /**
     * Return the prefixed key.
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
        return $this->data[$this->key($key)] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$this->key($key)] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return array_key_exists($this->key($key), $this->data);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        unset($this->data[$this->key($key)]);
    }
}
