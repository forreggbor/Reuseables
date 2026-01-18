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
        $sql = "SELECT * FROM license_info WHERE status IN ('active', 'expired') ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * {@inheritDoc}
     */
    public function saveLicenseInfo(array $data): bool
    {
        $licenseInfo = $this->getLicenseInfo();

        if ($licenseInfo === null) {
            return false;
        }

        $id = (int) $licenseInfo['id'];
        $setClauses = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $setClauses[] = "`{$key}` = :{$key}";
            $params[":{$key}"] = $value;
        }

        if (empty($setClauses)) {
            return true;
        }

        $sql = "UPDATE license_info SET " . implode(', ', $setClauses) . " WHERE id = :id";
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
}
