<?php

declare(strict_types=1);

namespace LicenseModule;

use LicenseModule\Contracts\DatabaseAdapterInterface;
use LicenseModule\Contracts\HttpClientInterface;
use LicenseModule\Exceptions\RateLimitedException;

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
     * @return array Always contains 'success' (bool), 'status' (string), 'message' (string).
     *               Conditionally present: 'data' (raw server response) only when the
     *               server was actually reached (both the valid and invalid-but-reachable
     *               branches) — absent on a throttled (429) result and on all offline
     *               results; 'throttled' => true only on a 429 result; 'offline' => true
     *               only on the cached-fallback results. Do not assume 'data' is always set.
     */
    public function validate(string $licenseKey, string $domain): array
    {
        try {
            $response = $this->sendValidationRequest($licenseKey);

            if ($response['valid']) {
                $updateData = [
                    'license_key' => $licenseKey,
                    'status' => $response['status'],
                    'validated_at' => date('Y-m-d H:i:s'),
                    'last_check_at' => date('Y-m-d H:i:s'),
                    'expires_at' => $response['expires_at'] ?? null,
                    'license_type' => $response['license_type'] ?? 'standard',
                    'licensed_domain' => $domain,
                    'features' => json_encode($response['features'] ?? ['all'], JSON_UNESCAPED_UNICODE),
                    'grace_expires_at' => $response['grace_expires_at'] ?? null,
                ];

                $this->updateLicenseInfo($updateData);

                // Unfiltered lookup — the row we just wrote may now have a status
                // (e.g. suspended/invalid) excluded by getLicenseInfo()'s filter,
                // which would otherwise silently skip logging this attempt.
                $licenseInfo = $this->database->getLatestLicenseInfo();
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
                'license_key' => $licenseKey,
                'status' => $response['status'],
                'last_check_at' => date('Y-m-d H:i:s'),
            ]);

            // Unfiltered — see the identical comment in the success branch above.
            $licenseInfo = $this->database->getLatestLicenseInfo();
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
        } catch (RateLimitedException) {
            return [
                'success'   => false,
                'status'    => LicenseStatus::THROTTLED,
                'message'   => 'License server is temporarily rate-limiting this client; please retry later.',
                'throttled' => true,
            ];
        } catch (\Exception $e) {
            // Unfiltered — see the identical comment in the success branch above.
            $licenseInfo = $this->database->getLatestLicenseInfo();
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
     * @throws RateLimitedException When the server responds with 429 Too Many Requests
     * @throws \Exception On connection or parsing errors
     */
    private function sendValidationRequest(string $licenseKey): array
    {
        $response = $this->httpClient->post(
            $this->serverUrl,
            ['license_key' => $licenseKey]
        );

        if ($response['status_code'] === 429) {
            throw new RateLimitedException();
        }

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
                'description' => $addon['description'] ?? null,
            ];
        }

        // Parse feature keys
        $featureKeys = $result['data']['features'] ?? [];

        // Parse addon catalog (all addons in the license's package, not just enabled ones)
        $rawAddonCatalog = $result['data']['addon_catalog'] ?? [];
        $addonCatalog = [];

        foreach ($rawAddonCatalog as $catalogAddon) {
            $addonCatalog[] = [
                'feature_key' => $catalogAddon['feature_key'] ?? null,
                'name' => $catalogAddon['name'] ?? null,
                'description' => $catalogAddon['description'] ?? null,
                'price' => $catalogAddon['price'] ?? null,
                'price_currency' => $catalogAddon['price_currency'] ?? null,
                'billing_period' => $catalogAddon['billing_period'] ?? null,
                'requires_tier_level' => $catalogAddon['requires_tier_level'] ?? null,
                'status' => $catalogAddon['status'] ?? null,
                'sort_order' => $catalogAddon['sort_order'] ?? null,
                'activated' => (bool) ($catalogAddon['activated'] ?? false),
                'tier_eligible' => (bool) ($catalogAddon['tier_eligible'] ?? false),
            ];
        }

        // Build features structure
        if ($tier !== null || $package !== null) {
            $features = [
                'package' => $package,
                'tier' => $tier,
                'addons' => $addons,
                'feature_keys' => $featureKeys,
                'addon_catalog' => $addonCatalog,
            ];
        } else {
            $features = ['all'];
        }

        $parsed = [
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

        // Extract server-side grace period information
        if (!empty($result['data']['in_grace_period'])) {
            $parsed['in_grace_period'] = true;
            $parsed['grace_expires_at'] = $result['data']['grace_expires_at'] ?? null;
        }

        return $parsed;
    }

    /**
     * Handle offline mode when license server is unreachable
     *
     * Uses the unfiltered accessor (not getLicenseInfo()) so an already-blocked
     * (suspended/invalid) license is visible here — otherwise this method would
     * wrongly report "no license information found" for a blocked license going
     * offline, or worse, let the grace-period logic below silently un-block it.
     *
     * @param string $error Error message
     * @return array Validation result
     */
    private function handleOfflineMode(string $error): array
    {
        $licenseInfo = $this->database->getLatestLicenseInfo();

        if ($licenseInfo === null) {
            return [
                'success' => false,
                'status' => LicenseStatus::INVALID,
                'message' => 'No license information found',
            ];
        }

        $cachedStatus = $licenseInfo['status'] ?? LicenseStatus::INVALID;

        // A license already blocked is a terminal, deliberate server-side decision —
        // offline-grace machinery must never un-block it. Return the cached blocked
        // status verbatim and skip the grace-period-days logic entirely.
        if (in_array($cachedStatus, [LicenseStatus::SUSPENDED, LicenseStatus::INVALID], true)) {
            $this->database->logValidation(
                (int) $licenseInfo['id'],
                'error',
                [],
                'Offline mode (cached blocked status ' . $cachedStatus . '): ' . $error
            );

            return [
                'success' => false,
                'status' => $cachedStatus,
                'message' => 'Using cached license status (offline mode): ' . $error,
                'offline' => true,
            ];
        }

        // Missing/unparseable validated_at defaults to epoch (0), NOT null — this
        // preserves the original conservative '1970-01-01' fallback: a license with
        // no readable last-validation timestamp degrades toward read-only, never
        // toward "still valid indefinitely".
        $lastValidation = self::parseTimestamp($licenseInfo['validated_at'] ?? null) ?? 0;

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
     * Uses the unfiltered accessor (not getLicenseInfo()) so a genuinely suspended
     * or invalid license is visible here — getLicenseInfo()'s status filter
     * previously made this method blind to those two statuses, silently reporting
     * them both as INVALID (so isSuspended() could never return true).
     *
     * @return string License status
     */
    public function getCurrentStatus(): string
    {
        $licenseInfo = $this->database->getLatestLicenseInfo();

        if ($licenseInfo === null) {
            return LicenseStatus::INVALID;
        }

        $status = $licenseInfo['status'] ?? LicenseStatus::INVALID;

        // Blocked statuses are terminal — never let the date-based reclassification
        // below downgrade a suspended/invalid license into merely "expired"
        // (read-only). Mirrors LicenseModule::deriveDisplayStatus()'s identical guard.
        if (in_array($status, [LicenseStatus::SUSPENDED, LicenseStatus::INVALID], true)) {
            return $status;
        }

        // If in grace period, check if grace has expired locally
        if ($status === LicenseStatus::GRACE) {
            $graceExpiry = self::parseTimestamp($licenseInfo['grace_expires_at'] ?? null);

            if ($graceExpiry !== null && $graceExpiry < time()) {
                return LicenseStatus::EXPIRED;
            }

            return LicenseStatus::GRACE;
        }

        // Check if license is expired by date. An unparseable expiry date means
        // "don't reclassify" rather than "treat as past".
        $expiry = self::parseTimestamp($licenseInfo['expires_at'] ?? null);

        if ($expiry !== null && $expiry < time()) {
            return LicenseStatus::EXPIRED;
        }

        return $status;
    }

    /**
     * Check if periodic validation is due
     *
     * Uses the unfiltered accessor so a blocked (suspended/invalid) license
     * respects the normal validation_frequency cadence like any other license,
     * instead of always being immediately "due" (which would otherwise hammer
     * the license server on every check for a blocked license).
     *
     * @return bool
     */
    public function isValidationDue(): bool
    {
        $licenseInfo = $this->database->getLatestLicenseInfo();

        if ($licenseInfo === null) {
            return true;
        }

        $lastCheck = self::parseTimestamp($licenseInfo['last_check_at'] ?? $licenseInfo['validated_at'] ?? null) ?? 0;
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
     * Get validation history for the current license.
     *
     * Returns rows in reverse chronological order (most recent first).
     * Returns an empty array if no license row exists.
     *
     * @param int $limit Maximum number of history rows to return (default 20)
     * @return array
     */
    public function getValidationHistory(int $limit = 20): array
    {
        $licenseInfo = $this->database->getLatestLicenseInfo();

        if ($licenseInfo === null) {
            return [];
        }

        $id = (int) $licenseInfo['id'];

        return $this->database->getValidationHistory($id, $limit);
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

    /**
     * Parse a datetime string to a Unix timestamp, or null if empty/unparseable.
     *
     * Guards against strtotime() returning false on a malformed date, which would
     * otherwise silently coerce to 0 in arithmetic and produce nonsense results
     * (e.g. a huge negative "days remaining"). Mirrors the equivalent defensive
     * closure already used in views/admin/license.php.
     *
     * @param string|null $value
     * @return int|null
     */
    private static function parseTimestamp(?string $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        $ts = strtotime($value);

        return ($ts !== false && $ts > 0) ? $ts : null;
    }
}
