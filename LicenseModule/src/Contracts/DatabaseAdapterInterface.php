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

    /**
     * Fetch the most recently inserted license_info row regardless of status
     *
     * Unlike getLicenseInfo(), this method applies no status filter, making
     * suspended and invalid rows visible. Intended for admin pages.
     *
     * @return array|null License data array or null if the table is empty
     */
    public function getLatestLicenseInfo(): ?array;

    /**
     * Fetch validation history rows for a given license in reverse chronological order
     *
     * @param int $licenseId License ID to retrieve history for
     * @param int $limit Maximum number of rows to return
     * @return array Array of history rows (empty array if none found)
     */
    public function getValidationHistory(int $licenseId, int $limit): array;
}
