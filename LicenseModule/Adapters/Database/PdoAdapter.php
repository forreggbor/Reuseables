<?php

declare(strict_types=1);

namespace LicenseModule\Adapters\Database;

use LicenseModule\Contracts\DatabaseAdapterInterface;
use PDO;

/**
 * PDO database adapter for license module
 *
 * Uses prepared statements for all database operations.
 */
class PdoAdapter implements DatabaseAdapterInterface
{
    private PDO $pdo;

    /**
     * @param PDO $pdo PDO connection instance
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritDoc}
     */
    public function getLicenseInfo(): ?array
    {
        $sql = "SELECT * FROM license_info WHERE status IN ('active', 'grace', 'expired') ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * {@inheritDoc}
     *
     * Checks for any existing row without a status filter so that the first call
     * (empty table) performs an INSERT, and subsequent calls perform an UPDATE.
     * getLicenseInfo() filters by status and would return null for invalid/suspended
     * rows, causing a spurious second INSERT — this query avoids that.
     */
    public function saveLicenseInfo(array $data): bool
    {
        $stmt    = $this->pdo->query('SELECT id FROM license_info ORDER BY id DESC LIMIT 1');
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing === false) {
            if (empty($data['license_key'])) {
                return false;
            }

            $columns      = implode(', ', array_map(fn(string $k): string => "`{$k}`", array_keys($data)));
            $placeholders = implode(', ', array_map(fn(string $k): string => ":{$k}", array_keys($data)));
            $sql          = "INSERT INTO license_info ({$columns}) VALUES ({$placeholders})";
            $stmt         = $this->pdo->prepare($sql);
            $params       = [];

            foreach ($data as $k => $v) {
                $params[":{$k}"] = $v;
            }

            return $stmt->execute($params);
        }

        $id         = (int) $existing['id'];
        $setClauses = [];
        $params     = [':id' => $id];

        foreach ($data as $key => $value) {
            $setClauses[] = "`{$key}` = :{$key}";
            $params[":{$key}"] = $value;
        }

        if (empty($setClauses)) {
            return true;
        }

        $sql  = "UPDATE license_info SET " . implode(', ', $setClauses) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * {@inheritDoc}
     */
    public function logValidation(int $licenseId, string $status, array $responseData = [], string $errorMessage = ''): bool
    {
        $sql = "INSERT INTO license_validation_history
                (license_id, validation_time, status, response_data, error_message)
                VALUES (:license_id, :validation_time, :status, :response_data, :error_message)";

        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            ':license_id' => $licenseId,
            ':validation_time' => date('Y-m-d H:i:s'),
            ':status' => $status,
            ':response_data' => !empty($responseData) ? json_encode($responseData, JSON_UNESCAPED_UNICODE) : null,
            ':error_message' => !empty($errorMessage) ? $errorMessage : null,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getLatestLicenseInfo(): ?array
    {
        $sql  = "SELECT * FROM license_info ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getValidationHistory(int $licenseId, int $limit): array
    {
        $sql  = "SELECT validation_time, status, response_data, error_message
                 FROM license_validation_history
                 WHERE license_id = :id
                 ORDER BY validation_time DESC
                 LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $licenseId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
