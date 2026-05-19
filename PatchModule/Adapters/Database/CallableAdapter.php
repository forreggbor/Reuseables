<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * CallableAdapter - Lazy PDO factory adapter for patch management database operations
 */

namespace PatchModule\Adapters\Database;

use PatchModule\Contracts\DatabaseAdapterInterface;
use PDO;

/**
 * Lazy PDO factory adapter for patch management database operations
 *
 * Wraps a callable that returns a PDO instance, deferring the database
 * connection until first use. This is important for scenarios where the
 * module is instantiated before the database is available (e.g., during
 * application bootstrap).
 *
 * @package PatchModule
 */
class CallableAdapter implements DatabaseAdapterInterface
{
    /** @var callable():PDO */
    private $pdoFactory;

    /** @var PdoAdapter|null Lazily initialized inner adapter */
    private ?PdoAdapter $adapter = null;

    /**
     * @param callable $pdoFactory A callable that returns a PDO instance
     */
    public function __construct(callable $pdoFactory)
    {
        $this->pdoFactory = $pdoFactory;
    }

    /**
     * Get or create the inner PdoAdapter
     *
     * @return PdoAdapter
     * @throws \RuntimeException If the factory returns null or non-PDO value
     */
    private function getAdapter(): PdoAdapter
    {
        if ($this->adapter === null) {
            $pdo = ($this->pdoFactory)();
            if (!$pdo instanceof PDO) {
                throw new \RuntimeException('PatchModule PDO factory must return a PDO instance');
            }
            $this->adapter = new PdoAdapter($pdo);
        }
        return $this->adapter;
    }

    /**
     * {@inheritdoc}
     */
    public function getHistoryRecord(int $id): ?array
    {
        return $this->getAdapter()->getHistoryRecord($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findHistoryByVersion(string $version, array $statuses = ['available', 'downloading']): ?array
    {
        return $this->getAdapter()->findHistoryByVersion($version, $statuses);
    }

    /**
     * {@inheritdoc}
     */
    public function findLatestHistoryByVersion(string $version): ?array
    {
        return $this->getAdapter()->findLatestHistoryByVersion($version);
    }

    /**
     * {@inheritdoc}
     */
    public function getCompletedVersions(): array
    {
        return $this->getAdapter()->getCompletedVersions();
    }

    /**
     * {@inheritdoc}
     */
    public function getHistory(): array
    {
        return $this->getAdapter()->getHistory();
    }

    /**
     * {@inheritdoc}
     */
    public function findUploadedAvailablePatches(): array
    {
        return $this->getAdapter()->findUploadedAvailablePatches();
    }

    /**
     * {@inheritdoc}
     */
    public function createHistoryRecord(array $data): int
    {
        return $this->getAdapter()->createHistoryRecord($data);
    }

    /**
     * {@inheritdoc}
     */
    public function updateHistoryRecord(int $id, array $data): bool
    {
        return $this->getAdapter()->updateHistoryRecord($id, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function getSetting(string $key): ?string
    {
        return $this->getAdapter()->getSetting($key);
    }

    /**
     * {@inheritdoc}
     */
    public function setSetting(string $key, ?string $value): bool
    {
        return $this->getAdapter()->setSetting($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getPdo(): PDO
    {
        return $this->getAdapter()->getPdo();
    }

    /**
     * {@inheritdoc}
     */
    public function findAvailableServerVersions(): array
    {
        return $this->getAdapter()->findAvailableServerVersions();
    }

    /**
     * {@inheritdoc}
     */
    public function markObsoleteByVersions(array $versions): int
    {
        return $this->getAdapter()->markObsoleteByVersions($versions);
    }
}