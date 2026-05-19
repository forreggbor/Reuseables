<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PdoAdapter - Direct PDO adapter for patch management database operations
 */

namespace PatchModule\Adapters\Database;

use PatchModule\Contracts\DatabaseAdapterInterface;
use PDO;

/**
 * Direct PDO adapter for patch management database operations
 *
 * Uses patch_history and patch_settings tables directly via PDO.
 * Suitable for projects without their own settings/config infrastructure.
 *
 * @package PatchModule
 */
class PdoAdapter implements DatabaseAdapterInterface
{
    /** @var PDO */
    private PDO $pdo;

    /**
     * @param PDO $pdo Active PDO connection
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // =========================================================================
    // Patch History
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getHistoryRecord(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM patch_history WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function findHistoryByVersion(string $version, array $statuses = ['available', 'downloading']): ?array
    {
        if (empty($statuses)) {
            return null;
        }

        $placeholders = [];
        $params = [':version' => $version];
        foreach ($statuses as $i => $status) {
            $key = ':status_' . $i;
            $placeholders[] = $key;
            $params[$key] = $status;
        }

        $sql = "SELECT * FROM patch_history WHERE version = :version AND status IN (" .
            implode(', ', $placeholders) . ") ORDER BY id DESC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function getCompletedVersions(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT version FROM patch_history WHERE status = 'completed'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritdoc}
     */
    public function getHistory(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM patch_history ORDER BY created_at DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function findLatestHistoryByVersion(string $version): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM patch_history WHERE version = :version ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':version' => $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function findUploadedAvailablePatches(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM patch_history WHERE patch_server_id IS NULL AND status = 'available' ORDER BY version ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function findAvailableServerVersions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT version FROM patch_history WHERE patch_server_id IS NOT NULL AND status = 'available'"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritdoc}
     */
    public function markObsoleteByVersions(array $versions): int
    {
        if (empty($versions)) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($versions as $i => $version) {
            $key = ':v_' . $i;
            $placeholders[] = $key;
            $params[$key] = $version;
        }

        $sql = "UPDATE patch_history SET status = 'obsolete'
                WHERE patch_server_id IS NOT NULL
                  AND status IN ('available', 'downloading')
                  AND version IN (" . implode(', ', $placeholders) . ")";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function createHistoryRecord(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO patch_history (version, status, release_notes, file_size, sha256_hash, patch_server_id, checked_at, released_at)
             VALUES (:version, :status, :notes, :size, :sha256, :server_id, NOW(), :released)"
        );
        $stmt->execute([
            ':version' => $data['version'],
            ':status' => $data['status'] ?? 'available',
            ':notes' => $data['release_notes'] ?? null,
            ':size' => $data['file_size'] ?? null,
            ':sha256' => $data['sha256_hash'] ?? null,
            ':server_id' => $data['patch_server_id'] ?? null,
            ':released' => $data['released_at'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function updateHistoryRecord(int $id, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $sets = [];
        $params = [':id' => $id];
        foreach ($data as $column => $value) {
            $paramKey = ':' . $column;
            $sets[] = "`{$column}` = {$paramKey}";
            $params[$paramKey] = $value;
        }

        $sql = "UPDATE patch_history SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    // =========================================================================
    // Settings Cache
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getSetting(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM patch_settings WHERE setting_key = :key");
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : null;
    }

    /**
     * {@inheritdoc}
     */
    public function setSetting(string $key, ?string $value): bool
    {
        if ($value === null) {
            $stmt = $this->pdo->prepare("DELETE FROM patch_settings WHERE setting_key = :key");
            return $stmt->execute([':key' => $key]);
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO patch_settings (setting_key, setting_value, updated_at)
             VALUES (:key, :value, NOW())
             ON DUPLICATE KEY UPDATE setting_value = :value2, updated_at = NOW()"
        );
        return $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
    }

    // =========================================================================
    // Raw PDO Access
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}