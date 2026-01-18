<?php

declare(strict_types=1);

namespace LicenseModule\Contracts;

/**
 * Database adapter interface for license module
 *
 * Abstracts database operations for framework independence.
 */
interface DatabaseAdapterInterface
{
    /**
     * Get active license information
     *
     * @return array|null License data array or null if not found
     */
    public function getLicenseInfo(): ?array;

    /**
     * Save/update license information
     *
     * @param array $data License data to save
     * @return bool Success status
     */
    public function saveLicenseInfo(array $data): bool;

    /**
     * Log a validation attempt
     *
     * @param int $licenseId License ID
     * @param string $status Validation status (success, expired, invalid, suspended, error)
     * @param array $responseData Server response data
     * @param string $errorMessage Error message if applicable
     * @return bool Success status
     */
    public function logValidation(int $licenseId, string $status, array $responseData = [], string $errorMessage = ''): bool;
}
