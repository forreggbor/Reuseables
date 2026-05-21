<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Lazy-PDO implementation of DatabaseAdapterInterface for CronAdmin.
 */

declare(strict_types=1);

namespace CronAdmin\Adapters\Database;

use CronAdmin\Contracts\DatabaseAdapterInterface;
use PDO;

/**
 * Wraps a callable PDO factory so the connection is only opened on first use.
 *
 * Useful when the CronAdmin facade is constructed at bootstrap time but you
 * want to defer the DB connection until the module is actually called (e.g.
 * in a request where the cron admin page is never visited).
 *
 * IMPORTANT: The factory MUST return the same PDO instance every call (i.e.
 * return the application's existing singleton), not create a new connection
 * each time. The returned PDO must be the same instance passed to
 * ActivityLogs\ActivityLogger::init($pdo) so audit log writes share the
 * same transaction context.
 */
class CallableAdapter implements DatabaseAdapterInterface
{
    private ?PdoAdapter $inner = null;

    /**
     * @param \Closure $factory  Zero-argument closure returning a PDO instance. Called at most once; cached.
     */
    public function __construct(private readonly \Closure $factory) {}

    /**
     * Returns the inner PdoAdapter, initialising it on first call.
     *
     * @return PdoAdapter
     */
    private function adapter(): PdoAdapter
    {
        if ($this->inner === null) {
            $pdo = ($this->factory)();
            if (!($pdo instanceof PDO)) {
                throw new \LogicException('CronAdmin CallableAdapter factory must return a PDO instance.');
            }
            $this->inner = new PdoAdapter($pdo);
        }
        return $this->inner;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->adapter()->fetchAll($sql, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->adapter()->fetchOne($sql, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->adapter()->execute($sql, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): string
    {
        return $this->adapter()->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function withTransaction(callable $fn): mixed
    {
        return $this->adapter()->withTransaction($fn);
    }
}
