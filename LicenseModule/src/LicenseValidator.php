<?php

declare(strict_types=1);

namespace LicenseModule;

use LicenseModule\Contracts\DatabaseAdapterInterface;
use LicenseModule\Contracts\HttpClientInterface;

/**
 * Core license validation logic
 *
 * Handles online validation with the license server, caching, and offline grace period.
 */
class LicenseValidator
{
    private DatabaseAdapterInterface $database;
    private HttpClientInterface $httpClient;
    private string $serverUrl;
    private GracePeriodManager $gracePeriodManager;

    /** @var callable|null Optional logging callback */
    private $logCallback;

    /**
     * @param DatabaseAdapterInterface $database Database adapter
     * @param HttpClientInterface $httpClient HTTP client
     * @param string $serverUrl License server URL
     * @param callable|null $logCallback Optional callback for logging: fn(string $message, string $level)
     */
    public function __construct(
        DatabaseAdapterInterface $database,
        HttpClientInterface $httpClient,
        string $serverUrl,
        ?callable $logCallback = null
    ) {
        $this->database = $database;
        $this->httpClient = $httpClient;
        $this->serverUrl = $serverUrl;
        $this->logCallback = $logCallback;
        $this->gracePeriodManager = new GracePeriodManager();
    }

    /**
     * Validate license with the license server
     *
     * @param string $licenseKey License key to validate
     * @param string $domain Domain to validate against
     * @return array Validation result with keys: success, status, message, data
     */
    public function validate(string $licenseKey, string $domain): array
    {
        try {
            $response = $this->sendValidationRequest($licenseKey);

            if ($response['valid']) {
                $this->updateLicenseInfo([
                    'status' => $response['status'],
                    'validated_at' => date('Y-m-d H:i:s'),
                    'last_check_at' => date('Y-m-d H:i:s'),
                    'expires_at' => $response['expires_at'] ?? null,
                    'license_type' => $response['license_type'] ?? 'standard',
                    'licensed_domain' => $domain,
                    'features' => json_encode($response['features'] ?? ['all'], JSON_UNESCAPED_UNICODE),
                ]);

                $licenseInfo = $this->database->getLicenseInfo();
                if ($licenseInfo !== null) {
                    $this->database->logValidation(
                        (int) $licenseInfo['id'],
                        'success',
                        $response
                    );
                }

                return [
                    'success' => true,
                    'status' => $response['status'],
                    'message' => $response['message'] ?? 'License is valid and active',
                    'data' => $response,
                ];
            }

            $this->updateLicenseInfo([
                'status' => $response['status'],
                'last_check_at' => date('Y-m-d H:i:s'),
            ]);

            $licenseInfo = $this->database->getLicenseInfo();
            if ($licenseInfo !== null) {
                $this->database->logValidation(
                    (int) $licenseInfo['id'],
                    $response['status'],
                    $response,
                    $response['message'] ?? 'Validation failed'
                );
            }

            return [
                'success' => false,
                'status' => $response['status'],
                'message' => $response['message'] ?? 'License validation failed',
                'data' => $response,
            ];
        } catch (\Exception $e) {
            $licenseInfo = $this->database->getLicenseInfo();
            if ($licenseInfo !== null) {
                $this->database->logValidation(
                    (int) $licenseInfo['id'],
                    'error',
                    [],
                    $e->getMessage()
                );
            }

            return $this->handleOfflineMode($e->getMessage());
        }
    }

    /**
     * Send validation request to license server
     *
     * @param string $licenseKey License key
     * @return array Parsed and normalized response
     * @throws \Exception On connection or parsing errors
     */
    private function sendValidationRequest(string $licenseKey): array
    {
        $response = $this->httpClient->post(
            $this->serverUrl,
            ['license_key' => $licenseKey]
        );

        if (!$response['success']) {
            throw new \Exception('License server connection failed: ' . ($response['error'] ?? 'Unknown error'));
        }

        if (empty($response['body'])) {
            throw new \Exception('License server returned empty response');
        }

        $result = json_decode($response['body'], true);

        if ($result === null) {
            throw new \Exception('License server returned invalid JSON');
        }

        $this->log('License API raw response: ' . json_encode($result, JSON_UNESCAPED_UNICODE), 'DEBUG');

        if (!isset($result['data']['valid'])) {
            throw new \Exception('License server returned unexpected format');
        }

        return $this->parseServerResponse($result);
    }

    /**
     * Parse server response into normalized format
     *
     * @param array $result Raw server response
     * @return array Normalized response
     */
    private function parseServerResponse(array $result): array
    {
        $isValid = $result['data']['valid'] === true;
        $serverStatus = $result['data']['status'] ?? 'unknown';
        $mappedStatus = LicenseStatus::mapFromServer($serverStatus, $isValid);

        // Parse tier
        $tierData = $result['data']['tier'] ?? null;
        $tier = null;
        $tierSlug = null;

        if (is_array($tierData)) {
            $tier = [
                'slug' => $tierData['slug'] ?? null,
                'name' => $tierData['name'] ?? null,
                'level' => (int) ($tierData['level'] ?? 0),
                'description' => $tierData['description'] ?? null,
            ];
            $tierSlug = $tier['slug'];
        }

        // Parse package
        $packageData = $result['data']['package'] ?? null;
        $package = null;

        if (is_array($packageData)) {
            $package = [
                'id' => $packageData['id'] ?? null,
                'name' => $packageData['name'] ?? null,
                'slug' => $packageData['slug'] ?? null,
            ];
        }

        // Parse addons
        $rawAddons = $result['data']['addons'] ?? [];
        $addons = [];

        foreach ($rawAddons as $addon) {
            $addons[] = [
                'feature_key' => $addon['feature_key'] ?? null,
                'name' => $addon['name'] ?? null,
                'slug' => $addon['slug'] ?? null,
                'description' => $addon['description'] ?? null,
            ];
        }

        // Parse feature keys
        $featureKeys = $result['data']['features'] ?? [];

        // Build features structure
        if ($tier !== null || $package !== null) {
            $features = [
                'package' => $package,
                'tier' => $tier,
                'addons' => $addons,
                'feature_keys' => $featureKeys,
            ];
        } else {
            $features = ['all'];
        }

        return [
            'valid' => $isValid,
            'status' => $mappedStatus,
            'server_status' => $serverStatus,
            'message' => $result['data']['message'] ?? ($isValid ? 'License is valid' : 'License validation failed'),
            'license_type' => $tierSlug ?? 'standard',
            'expires_at' => $result['data']['expiry_date'] ?? null,
            'client_name' => $result['data']['client_name'] ?? null,
            'package' => $package,
            'features' => $features,
        ];
    }

    /**
     * Handle offline mode when license server is unreachable
     *
     * @param string $error Error message
     * @return array Validation result
     */
    private function handleOfflineMode(string $error): array
    {
        $licenseInfo = $this->database->getLicenseInfo();

        if ($licenseInfo === null) {
            return [
                'success' => false,
                'status' => LicenseStatus::INVALID,
                'message' => 'No license information found',
            ];
        }

        $lastValidation = strtotime($licenseInfo['validated_at'] ?? '1970-01-01');

        if ($this->gracePeriodManager->isExpired($lastValidation)) {
            $this->updateLicenseInfo([
                'status' => LicenseStatus::EXPIRED,
                'last_check_at' => date('Y-m-d H:i:s'),
            ]);

            $this->database->logValidation(
                (int) $licenseInfo['id'],
                'error',
                [],
                sprintf('Offline period exceeded %d days', $this->gracePeriodManager->getGracePeriodDays())
            );

            return [
                'success' => false,
                'status' => LicenseStatus::EXPIRED,
                'message' => sprintf(
                    'License server unreachable for more than %d days. System is in read-only mode.',
                    $this->gracePeriodManager->getGracePeriodDays()
                ),
            ];
        }

        $this->updateLicenseInfo([
            'last_check_at' => date('Y-m-d H:i:s'),
        ]);

        $this->database->logValidation(
            (int) $licenseInfo['id'],
            'error',
            [],
            'Offline mode: ' . $error
        );

        return [
            'success' => true,
            'status' => $licenseInfo['status'],
            'message' => 'Using cached license status (offline mode): ' . $error,
            'offline' => true,
        ];
    }

    /**
     * Get current license status
     *
     * @return string License status
     */
    public function getCurrentStatus(): string
    {
        $licenseInfo = $this->database->getLicenseInfo();

        if ($licenseInfo === null) {
            return LicenseStatus::INVALID;
        }

        // Check if license is expired by date
        if (!empty($licenseInfo['expires_at']) && strtotime($licenseInfo['expires_at']) < time()) {
            return LicenseStatus::EXPIRED;
        }

        return $licenseInfo['status'] ?? LicenseStatus::INVALID;
    }

    /**
     * Check if periodic validation is due
     *
     * @return bool
     */
    public function isValidationDue(): bool
    {
        $licenseInfo = $this->database->getLicenseInfo();

        if ($licenseInfo === null) {
            return true;
        }

        $lastCheck = strtotime($licenseInfo['last_check_at'] ?? $licenseInfo['validated_at'] ?? '1970-01-01');
        $validationFrequency = (int) ($licenseInfo['validation_frequency'] ?? 24);

        $hoursSinceLastCheck = (time() - $lastCheck) / 3600;

        return $hoursSinceLastCheck >= $validationFrequency;
    }

    /**
     * Get license information
     *
     * @return array|null License data or null if not found
     */
    public function getLicenseInfo(): ?array
    {
        return $this->database->getLicenseInfo();
    }

    /**
     * Update license information
     *
     * @param array $data Data to update
     * @return bool Success status
     */
    private function updateLicenseInfo(array $data): bool
    {
        return $this->database->saveLicenseInfo($data);
    }

    /**
     * Log a message
     *
     * @param string $message Log message
     * @param string $level Log level
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logCallback !== null) {
            ($this->logCallback)($message, $level);
        }
    }
}
