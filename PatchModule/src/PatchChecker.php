<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\HttpClientInterface;
use PatchModule\Contracts\LoggerInterface;
use PatchModule\Contracts\VersionResolverInterface;

/**
 * PatchChecker - Check for updates, manage cache, and dismiss patches
 *
 * Contacts the patch server to check for available updates, caches results
 * in the database settings, manages dismissed versions, and synchronizes
 * patch_history records.
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

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var string Patch server base URL */
    private string $serverUrl;

    /** @var int Cache duration in hours */
    private int $checkCacheHours;

    /** @var int API timeout in seconds */
    private int $apiTimeout;

    /**
     * @param DatabaseAdapterInterface $database Database adapter
     * @param HttpClientInterface $httpClient HTTP client
     * @param VersionResolverInterface $versionResolver Version resolver
     * @param string $serverUrl Patch server base URL
     * @param int $checkCacheHours Cache duration in hours
     * @param int $apiTimeout API request timeout in seconds
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        DatabaseAdapterInterface $database,
        HttpClientInterface $httpClient,
        VersionResolverInterface $versionResolver,
        string $serverUrl,
        int $checkCacheHours = 6,
        int $apiTimeout = 30,
        ?LoggerInterface $logger = null
    ) {
        $this->database = $database;
        $this->httpClient = $httpClient;
        $this->versionResolver = $versionResolver;
        $this->serverUrl = $serverUrl;
        $this->checkCacheHours = $checkCacheHours;
        $this->apiTimeout = $apiTimeout;
        $this->logger = $logger;
    }

    /**
     * Check for available updates from the patch server
     *
     * Results are cached for the configured duration. Uses the license key
     * and current application version to query the server.
     *
     * @param string $licenseKey License key for authentication
     * @param bool $forceCheck Skip cache and always contact the server
     * @return array{available: bool, count: ?int, patches: ?array, version: ?string, current_version: string, error: ?string}
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
                            'available' => true,
                            'count' => count($cached),
                            'patches' => $cached,
                            'version' => $cached[0]['version'],
                            'current_version' => $currentVersion,
                            'error' => null,
                        ];
                    }
                    return ['available' => false, 'count' => 0, 'patches' => [], 'version' => null, 'current_version' => $currentVersion, 'error' => null];
                }
            }
        }

        if (empty($licenseKey)) {
            $this->log('Patch check: no license key provided', 'WARNING');
            return ['available' => false, 'count' => 0, 'patches' => [], 'version' => null, 'current_version' => $currentVersion, 'error' => 'No license key provided'];
        }

        // Contact the patch server
        $checkUrl = $this->serverUrl . '/patches/check';
        $response = $this->httpClient->postJson($checkUrl, [
            'license_key' => $licenseKey,
            'current_version' => $currentVersion,
        ], $this->apiTimeout);

        if (!$response['success'] || $response['body'] === null) {
            $error = $response['error'] ?? 'Connection failed';
            $this->log('Patch check: request failed - ' . $error, 'WARNING');
            return ['available' => false, 'count' => 0, 'patches' => [], 'version' => null, 'current_version' => $currentVersion, 'error' => $error];
        }

        $responseData = json_decode($response['body'], true);
        if (!is_array($responseData)) {
            $this->log('Patch check: invalid response from server', 'WARNING');
            return ['available' => false, 'count' => 0, 'patches' => [], 'version' => null, 'current_version' => $currentVersion, 'error' => 'Invalid server response'];
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
            return ['available' => false, 'count' => 0, 'patches' => [], 'version' => null, 'current_version' => $currentVersion, 'error' => null];
        }

        // Process all available patches (sort by released_at ascending)
        $availablePatches = $responseData['data']['available_patches'];
        usort($availablePatches, function ($a, $b) {
            return strcmp($a['released_at'] ?? '', $b['released_at'] ?? '');
        });

        $patchesData = [];
        foreach ($availablePatches as $patch) {
            $patchItem = [
                'version' => $patch['version'],
                'release_notes' => $patch['release_notes'] ?? null,
                'file_size' => (int) ($patch['file_size'] ?? 0),
                'sha256' => $patch['sha256'] ?? null,
                'patch_id' => (int) ($patch['id'] ?? 0),
                'released_at' => $patch['released_at'] ?? null,
            ];
            $patchesData[] = $patchItem;

            // Create or update patch_history record for each patch
            $this->createOrUpdateHistoryRecord($patchItem);
        }

        // Cache all patches
        $this->database->setSetting('patch_available_data', json_encode($patchesData));

        $count = count($patchesData);
        $firstVersion = $patchesData[0]['version'];
        $lastVersion = $patchesData[$count - 1]['version'];
        $this->log(
            "Patch check: {$count} update(s) available - v{$firstVersion}" .
            ($count > 1 ? " to v{$lastVersion}" : '') .
            " (current: {$currentVersion})",
            'INFO'
        );

        return [
            'available' => true,
            'count' => $count,
            'patches' => $patchesData,
            'version' => $firstVersion,
            'current_version' => $currentVersion,
            'error' => null,
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