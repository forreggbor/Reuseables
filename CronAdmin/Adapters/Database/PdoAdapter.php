<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PDO-backed implementation of DatabaseAdapterInterface for CronAdmin.
 */

declare(strict_types=1);

namespace CronAdmin\Adapters\Database;

use CronAdmin\Contracts\DatabaseAdapterInterface;
use PDO;
use PDOException;

/**
 * Wraps an existing PDO connection and exposes the CronAdmin database contract.
 *
 * Use this adapter when the host can provide a ready PDO instance at construction
 * time. When the connection must be created lazily (e.g. to avoid opening a DB
 * connection unless the module is actually needed), use CallableAdapter instead.
 *
 * IMPORTANT: The PDO instance passed here MUST be the same one passed to
 * ActivityLogs\ActivityLogger::init($pdo) to ensure transactional consistency
 * between module writes and audit log writes.
 */
class PdoAdapter implements DatabaseAdapterInterface
{
    /**
     * @param PDO $pdo  An already-open PDO connection with error mode set to ERRMODE_EXCEPTION.
     */
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function withTransaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            // Already inside a transaction — join it rather than throwing.
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            // Swallow rollback errors so the original exception is not lost.
            try { $this->pdo->rollBack(); } catch (\Throwable) {}
            throw $e;
        }
    }
}
