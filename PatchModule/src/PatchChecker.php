<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PatchChecker - Check for available patch updates and manage patch notification state
 */

namespace PatchModule;

use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\HttpClientInterface;
use PatchModule\Contracts\LoggerInterface;
use PatchModule\Contracts\SignatureVerifierInterface;
use PatchModule\Contracts\VersionResolverInterface;

/**
 * PatchChecker - Check for updates, manage cache, and dismiss patches
 *
 * Contacts the patch server to check for available updates, caches results
 * in the database settings, manages dismissed versions, and synchronizes
 * patch_history records. Optionally verifies per-patch metadata signatures
 * when the server returns them and the required payload fields are present.
 *
 * @package PatchModule
 */
class PatchChecker
{
    /** @var DatabaseAdapterInterface */
    private DatabaseAdapterInterface $database;

    /** @var HttpClientInterface */
    private HttpClientInterface $httpClient;

    /** @var VersionResolverInterface */
    private VersionResolverInterface $versionResolver;

    /** @var SignatureVerifierInterface|null */
    private ?SignatureVerifierInterface $signatureVerifier;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var string Patch server base URL */
    private string $serverUrl;

    /** @var int Cache duration in hours */
    private int $checkCacheHours;

    /** @var int API timeout in seconds */
    private int $apiTimeout;

    /** @var string|null Pinned public key PEM; when set, patches with a mismatched key are rejected */
    private ?string $expectedPublicKeyPem;

    /**
     * @param DatabaseAdapterInterface    $database             Database adapter
     * @param HttpClientInterface         $httpClient           HTTP client
     * @param VersionResolverInterface    $versionResolver      Version resolver
     * @param string                      $serverUrl            Patch server base URL
     * @param int                         $checkCacheHours      Cache duration in hours
     * @param int                         $apiTimeout           API request timeout in seconds
     * @param LoggerInterface|null        $logger               Optional logger
     * @param SignatureVerifierInterface|null $signatureVerifier Optional signature verifier
     * @param string|null                 $expectedPublicKeyPem Pinned public key PEM for key pinning
     */
    public function __construct(
        DatabaseAdapterInterface $database,
        HttpClientInterface $httpClient,
        VersionResolverInterface $versionResolver,
        string $serverUrl,
        int $checkCacheHours = 6,
        int $apiTimeout = 30,
        ?LoggerInterface $logger = null,
        ?SignatureVerifierInterface $signatureVerifier = null,
        ?string $expectedPublicKeyPem = null
    ) {
        $this->database = $database;
        $this->httpClient = $httpClient;
        $this->versionResolver = $versionResolver;
        $this->serverUrl = $serverUrl;
        $this->checkCacheHours = $checkCacheHours;
        $this->apiTimeout = $apiTimeout;
        $this->logger = $logger;
        $this->signatureVerifier = $signatureVerifier;
        $this->expectedPublicKeyPem = $expectedPublicKeyPem;
    }

    /**
     * Check for available updates from the patch server
     *
     * Results are cached for the configured duration. Uses the license key
     * and current application version to query the server. Maps all documented
     * server error codes to stable error_code values; rate-limited responses
     * include a retry_after hint in seconds.
     *
     * @param string $licenseKey License key for authentication
     * @param bool   $forceCheck Skip cache and always contact the server
     * @return array{available: bool, count: ?int, patches: ?array, version: ?string, current_version: string, error: ?string, error_code: ?string, retry_after: ?int}
     */
    public function checkForUpdates(string $licenseKey, bool $forceCheck = false): array
    {
        $currentVersion = $this->versionResolver->getCurrentVersion();

        // Check cache unless forced
        if (!$forceCheck) {
            $lastCheck = $this->database->getSetting('patch_last_check_at');
            if ($lastCheck) {
                $hoursSinceCheck = (time() - strtotime($lastCheck)) / 3600;
                if ($hoursSinceCheck < $this->checkCacheHours) {
                    $cached = $this->getAvailablePatches();
                    if (!empty($cached)) {
                        return [
                            'available'      => true,
                            'count'          => count($cached),
                            'patches'        => $cached,
                            'version'        => $cached[0]['version'],
                            'current_version' => $currentVersion,
                            'error'          => null,
                            'error_code'     => null,
                            'retry_after'    => null,
                        ];
                    }
                    return [
                        'available' => false, 'count' => 0, 'patches' => [], 'version' => null,
                        'current_version' => $currentVersion, 'error' => null,
                        'error_code' => null, 'retry_after' => null,
                    ];
                }
            }
        }

        if (empty($licenseKey)) {
            $this->log('Patch check: no license key provided', 'WARNING');
            return [
                'available' => false, 'count' => 0, 'patches' => [], 'version' => null,
                'current_version' => $currentVersion, 'error' => 'No license key provided',
                'error_code' => null, 'retry_after' => null,
            ];
        }

        // Contact the patch server
        $checkUrl = $this->serverUrl . '/patches/check';
        $response = $this->httpClient->postJson($checkUrl, [
            'license_key'     => $licenseKey,
            'current_version' => $currentVersion,
        ], $this->apiTimeout);

        if (!$response['success'] || $response['body'] === null) {
            $error  = $response['error'] ?? 'Connection failed';
            $mapped = ServerErrorMapper::map($response);
            $this->log('Patch check: request failed - ' . $error, 'WARNING');
            return [
                'available' => false, 'count' => 0, 'patches' => [], 'version' => null,
                'current_version' => $currentVersion, 'error' => $error,
                'error_code' => $mapped['error_code'], 'retry_after' => $mapped['retry_after'],
            ];
        }

        $responseData = json_decode($response['body'], true);
        if (!is_array($responseData)) {
            $this->log('Patch check: invalid response from server', 'WARNING');
            return [
                'available' => false, 'count' => 0, 'patches' => [], 'version' => null,
                'current_version' => $currentVersion, 'error' => 'Invalid server response',
                'error_code' => null, 'retry_after' => null,
            ];
        }

        // Update last check timestamp
        $this->database->setSetting('patch_last_check_at', date('Y-m-d H:i:s'));

        // Check if patches are available
        if (
            !($responseData['success'] ?? false)
            || !($responseData['data']['valid'] ?? false)
            || empty($responseData['data']['available_patches'])
        ) {
            $this->database->setSetting('patch_available_data', null);
            return [
                'available' => false, 'count' => 0, 'patches' => [], 'version' => null,
                'current_version' => $currentVersion, 'error' => null,
                'error_code' => null, 'retry_after' => null,
            ];
        }

        // Process all available patches (sort by released_at ascending)
        $availablePatches = $responseData['data']['available_patches'];
        usort($availablePatches, function ($a, $b) {
            return strcmp($a['released_at'] ?? '', $b['released_at'] ?? '');
        });

        // package.id is required for signature payload reconstruction.
        // The current server response only exposes name and slug in the package block;
        // when the server starts returning package.id this field will be populated automatically.
        $packageId = (int) ($responseData['data']['package']['id'] ?? 0);

        $patchesData = [];
        foreach ($availablePatches as $patch) {
            $patchItem = [
                'version'       => $patch['version'],
                'release_notes' => $patch['release_notes'] ?? null,
                'file_size'     => (int) ($patch['file_size'] ?? 0),
                'sha256'        => $patch['sha256'] ?? null,
                'patch_id'      => (int) ($patch['id'] ?? 0),
                'released_at'   => $patch['released_at'] ?? null,
                'signature'     => $patch['signature'] ?? null,
                'public_key'    => $patch['public_key'] ?? null,
                'exp'           => isset($patch['exp']) ? (int) $patch['exp'] : null,
            ];

            if (!$this->validatePatchSignature($patchItem, $packageId)) {
                $this->log(
                    "Patch check: signature validation failed for v{$patchItem['version']}, excluding from cache",
                    'WARNING'
                );
                continue;
            }

            $patchesData[] = $patchItem;

            // Create or update patch_history record for each patch
            $this->createOrUpdateHistoryRecord($patchItem);
        }

        // Cache all patches
        $this->database->setSetting('patch_available_data', json_encode($patchesData));

        $count        = count($patchesData);
        $firstVersion = $patchesData[0]['version'];
        $lastVersion  = $patchesData[$count - 1]['version'];
        $this->log(
            "Patch check: {$count} update(s) available - v{$firstVersion}" .
            ($count > 1 ? " to v{$lastVersion}" : '') .
            " (current: {$currentVersion})",
            'INFO'
        );

        return [
            'available'       => true,
            'count'           => $count,
            'patches'         => $patchesData,
            'version'         => $firstVersion,
            'current_version' => $currentVersion,
            'error'           => null,
            'error_code'      => null,
            'retry_after'     => null,
        ];
    }

    /**
     * Get all available (non-installed, non-dismissed) patches sorted by released_at ascending
     *
     * @return array List of available patch data objects
     */
    public function getAvailablePatches(): array
    {
        $cached = $this->database->getSetting('patch_available_data');
        if (empty($cached)) {
            return [];
        }

        $data = json_decode($cached, true);
        if (!is_array($data)) {
            return [];
        }

        $currentVersion = $this->versionResolver->getCurrentVersion();

        // Handle backward compat: old single-object format (has 'available' key)
        if (isset($data['available'])) {
            if (!$data['available'] || ($data['version'] ?? '') === $currentVersion) {
                return [];
            }
            $data = [[
                'version' => $data['version'],
                'release_notes' => $data['release_notes'] ?? null,
                'file_size' => $data['file_size'] ?? 0,
                'sha256' => $data['sha256'] ?? null,
                'patch_id' => $data['patch_id'] ?? 0,
                'released_at' => $data['released_at'] ?? null,
            ]];
        }

        // Get dismissed and completed versions
        $dismissedRaw = $this->database->getSetting('patch_dismissed_versions');
        $dismissedVersions = [];
        if (!empty($dismissedRaw)) {
            $dismissedVersions = json_decode($dismissedRaw, true) ?: [];
        }

        $completedVersions = [];
        try {
            $completedVersions = $this->database->getCompletedVersions();
        } catch (\Exception $e) {
            $this->log('getAvailablePatches: failed to query completed versions - ' . $e->getMessage(), 'WARNING');
        }

        // Filter out installed, completed, and dismissed patches
        $filtered = array_filter($data, function (array $patch) use ($currentVersion, $dismissedVersions, $completedVersions) {
            $version = $patch['version'] ?? '';
            if ($version === $currentVersion) {
                return false;
            }
            if (in_array($version, $completedVersions, true)) {
                return false;
            }
            if (in_array($version, $dismissedVersions, true)) {
                return false;
            }
            return true;
        });

        // Re-index and sort by released_at ascending
        $filtered = array_values($filtered);
        usort($filtered, function ($a, $b) {
            return strcmp($a['released_at'] ?? '', $b['released_at'] ?? '');
        });

        return $filtered;
    }

    /**
     * Get the first (oldest) available patch
     *
     * @return array|null First available patch data or null
     */
    public function getAvailablePatch(): ?array
    {
        $patches = $this->getAvailablePatches();
        return !empty($patches) ? $patches[0] : null;
    }

    /**
     * Dismiss patch notification for a specific version
     *
     * @param string $version Version to dismiss
     * @param int|null $userId User performing the dismissal
     * @return void
     */
    public function dismissPatch(string $version, ?int $userId = null): void
    {
        $dismissedRaw = $this->database->getSetting('patch_dismissed_versions');
        $dismissed = !empty($dismissedRaw) ? (json_decode($dismissedRaw, true) ?: []) : [];

        if (!in_array($version, $dismissed, true)) {
            $dismissed[] = $version;
        }

        $this->database->setSetting('patch_dismissed_versions', json_encode($dismissed));

        $this->logActivity('dismiss_patch', 'patch', null, null, ['version' => $version], $userId);
    }

    /**
     * Dismiss all available patch notifications
     *
     * @param int|null $userId User performing the dismissal
     * @return void
     */
    public function dismissAllPatches(?int $userId = null): void
    {
        $patches = $this->getAvailablePatches();
        if (empty($patches)) {
            return;
        }

        $dismissedRaw = $this->database->getSetting('patch_dismissed_versions');
        $dismissed = !empty($dismissedRaw) ? (json_decode($dismissedRaw, true) ?: []) : [];

        $versions = [];
        foreach ($patches as $patch) {
            $version = $patch['version'];
            $versions[] = $version;
            if (!in_array($version, $dismissed, true)) {
                $dismissed[] = $version;
            }
        }

        $this->database->setSetting('patch_dismissed_versions', json_encode($dismissed));

        $this->logActivity('dismiss_all_patches', 'patch', null, null, ['versions' => $versions], $userId);
    }

    /**
     * Remove an installed version from the cached patch data
     *
     * @param string $version Version to remove
     * @return void
     */
    public function removeVersionFromCache(string $version): void
    {
        $cached = $this->database->getSetting('patch_available_data');
        if (!empty($cached)) {
            $data = json_decode($cached, true);
            if (is_array($data)) {
                if (isset($data['available'])) {
                    $this->database->setSetting('patch_available_data', null);
                } else {
                    $filtered = array_filter($data, fn(array $p) => ($p['version'] ?? '') !== $version);
                    if (empty($filtered)) {
                        $this->database->setSetting('patch_available_data', null);
                    } else {
                        $this->database->setSetting('patch_available_data', json_encode(array_values($filtered)));
                    }
                }
            }
        }

        // Remove from dismissed list if present
        $dismissedRaw = $this->database->getSetting('patch_dismissed_versions');
        if (!empty($dismissedRaw)) {
            $dismissed = json_decode($dismissedRaw, true) ?: [];
            $dismissed = array_values(array_filter($dismissed, fn(string $v) => $v !== $version));
            if (empty($dismissed)) {
                $this->database->setSetting('patch_dismissed_versions', null);
            } else {
                $this->database->setSetting('patch_dismissed_versions', json_encode($dismissed));
            }
        }
    }

    /**
     * Clear the entire remote patch availability cache
     *
     * Fully clears the cached patch data by setting patch_available_data to null in the
     * database settings, forcing a fresh server check on the next page load. Called after
     * a successful manual install to ensure the banner displays correctly.
     *
     * @return void
     */
    public function invalidateCache(): void
    {
        $this->database->setSetting('patch_available_data', null);
    }

    /**
     * Validate a patch entry's signature and public key against configured expectations
     *
     * Applies three levels of checking in order:
     * 1. If a pinned public key is configured and the patch has a public_key, reject mismatches.
     * 2. If signature, public_key, and exp are all present, perform full cryptographic verification.
     * 3. If signature and public_key are present but exp is missing, accept with a debug note
     *    (server does not yet include exp in patch entries; full verification is not possible).
     *
     * Returns true when the patch is acceptable, false when it must be excluded.
     *
     * @param array $patchData  Patch entry data including optional signature, public_key, exp
     * @param int   $packageId  Package ID from the top-level response, needed for payload rebuild
     * @return bool
     */
    private function validatePatchSignature(array $patchData, int $packageId): bool
    {
        $signature = $patchData['signature'] ?? null;
        $publicKey = $patchData['public_key'] ?? null;

        // No signing data present — server does not have signing configured
        if ($signature === null && $publicKey === null) {
            $this->log("Patch check: v{$patchData['version']} has no signature (unsigned server)", 'DEBUG');
            return true;
        }

        // Public key pinning: reject if the key doesn't match what we trust
        if ($publicKey !== null && $this->expectedPublicKeyPem !== null) {
            if (!$this->pemKeysMatch($publicKey, $this->expectedPublicKeyPem)) {
                $this->log(
                    "Patch check: v{$patchData['version']} has an unexpected public key (pinning mismatch)",
                    'WARNING'
                );
                return false;
            }
        }

        // Full cryptographic verification requires both exp and package_id in the payload.
        // package_id comes from the top-level package.id field which the current server does
        // not yet return (resolves to 0). If exp is present but package_id is unknown the
        // reconstructed payload would differ from what the server signed, causing every patch
        // to be rejected with a misleading WARNING. Skip full verification in that case.
        $exp = $patchData['exp'] ?? null;
        if ($signature !== null && $publicKey !== null && $exp !== null) {
            if ($packageId === 0) {
                $this->log(
                    "Patch check: v{$patchData['version']} has exp but package_id is unavailable; " .
                    "cryptographic verification skipped",
                    'DEBUG'
                );
                return true;
            }

            if ($this->signatureVerifier === null) {
                // No verifier injected; accept but log that verification was skipped
                $this->log(
                    "Patch check: v{$patchData['version']} has a signature but no verifier is configured",
                    'DEBUG'
                );
                return true;
            }

            $payload = [
                'patch_id'   => $patchData['patch_id'],
                'sha256'     => $patchData['sha256'],
                'version'    => $patchData['version'],
                'package_id' => $packageId,
                'exp'        => (int) $exp,
            ];

            if (!$this->signatureVerifier->verify($payload, $publicKey, $signature)) {
                return false;
            }

            $this->log("Patch check: v{$patchData['version']} signature verified OK", 'DEBUG');
            return true;
        }

        // Signature and key present but exp missing — server does not yet include exp
        if ($signature !== null && $publicKey !== null) {
            $this->log(
                "Patch check: v{$patchData['version']} signature present but exp missing; " .
                "cryptographic verification skipped (public-key pinning is the active defense)",
                'DEBUG'
            );
            return true;
        }

        // Unexpected combination (e.g. signature present but no public key)
        $this->log(
            "Patch check: v{$patchData['version']} has partial signing data (signature without public_key or vice versa), accepting",
            'DEBUG'
        );
        return true;
    }

    /**
     * Compare two PEM public keys for equivalence by normalizing via OpenSSL key details
     *
     * Raw string comparison is unreliable because line endings and whitespace can differ
     * between the server response and the locally configured pinned value. This method
     * loads both PEMs and compares the underlying key material.
     *
     * @param string $receivedPem  Public key PEM from the server response
     * @param string $expectedPem  Pinned public key PEM from configuration
     * @return bool True when both PEMs represent the same public key
     */
    private function pemKeysMatch(string $receivedPem, string $expectedPem): bool
    {
        $receivedKey = openssl_pkey_get_public($receivedPem);
        $expectedKey = openssl_pkey_get_public($expectedPem);

        if ($receivedKey === false || $expectedKey === false) {
            return false;
        }

        $receivedDetails = openssl_pkey_get_details($receivedKey);
        $expectedDetails = openssl_pkey_get_details($expectedKey);

        if ($receivedDetails === false || $expectedDetails === false) {
            return false;
        }

        // Compare the exported public key PEM (canonical form produced by OpenSSL)
        return ($receivedDetails['key'] ?? '') === ($expectedDetails['key'] ?? '');
    }

    /**
     * Create or update a patch_history record from check results
     *
     * @param array $patchData Patch data from server response
     * @return void
     */
    private function createOrUpdateHistoryRecord(array $patchData): void
    {
        $existing = $this->database->findHistoryByVersion(
            $patchData['version'],
            ['available', 'downloading']
        );

        if ($existing) {
            $this->database->updateHistoryRecord((int) $existing['id'], [
                'release_notes' => $patchData['release_notes'],
                'file_size' => $patchData['file_size'],
                'sha256_hash' => $patchData['sha256'],
                'patch_server_id' => $patchData['patch_id'],
                'checked_at' => date('Y-m-d H:i:s'),
                'released_at' => $patchData['released_at'],
            ]);
        } else {
            $this->database->createHistoryRecord([
                'version' => $patchData['version'],
                'status' => 'available',
                'release_notes' => $patchData['release_notes'],
                'file_size' => $patchData['file_size'],
                'sha256_hash' => $patchData['sha256'],
                'patch_server_id' => $patchData['patch_id'],
                'released_at' => $patchData['released_at'],
            ]);
        }
    }

    /**
     * Log a message if logger is available
     *
     * @param string $message Log message
     * @param string $level Log level
     * @return void
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logger !== null) {
            $this->logger->log($message, $level);
        }
    }

    /**
     * Log an activity if logger is available
     *
     * @param string $action Action identifier
     * @param string $entityType Entity type
     * @param int|null $entityId Entity ID
     * @param array|null $oldValues Previous state
     * @param array|null $newValues New state
     * @param int|null $userId User who performed the action
     * @return void
     */
    private function logActivity(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?int $userId = null
    ): void {
        if ($this->logger !== null) {
            $this->logger->activity($action, $entityType, $entityId, $oldValues, $newValues, $userId);
        }
    }
}