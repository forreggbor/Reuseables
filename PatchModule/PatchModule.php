<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Adapters\Archive\ExecTarAdapter;
use PatchModule\Adapters\Archive\PharTarAdapter;
use PatchModule\Adapters\Database\CallableAdapter;
use PatchModule\Adapters\Database\PdoAdapter;
use PatchModule\Adapters\Http\CurlHttpClient;
use PatchModule\Adapters\Signature\OpenSslSignatureVerifier;
use PatchModule\AdminActions;
use PatchModule\Contracts\ArchiveAdapterInterface;
use PatchModule\Contracts\AuthAdapterInterface;
use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\CsrfAdapterInterface;
use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\HttpClientInterface;
use PatchModule\Contracts\LoggerInterface;
use PatchModule\Contracts\SignatureVerifierInterface;
use PatchModule\Contracts\TranslatorInterface;
use PatchModule\Contracts\VersionResolverInterface;
use PDO;

/**
 * PatchModule - Framework-agnostic patch management
 *
 * Main facade providing a simple API for checking, downloading, installing,
 * and rolling back application patches. Uses adapter pattern for all external
 * dependencies (database, HTTP, archive, backup, logging, version management).
 *
 * @example
 * // Minimal setup
 * $module = new PatchModule([
 *     'get_pdo'          => fn() => $pdo,
 *     'patch_server_url' => 'https://lm.example.com/api/v1',
 *     'license_key'      => fn() => $licenseKey,
 *     'version_resolver' => new MyVersionResolver(),
 *     'root_path'        => '/var/www/project',
 *     'temp_path'        => '/var/www/project/storage/temp',
 * ]);
 *
 * // Check for updates
 * $result = $module->checkForUpdates();
 * if ($result['available']) {
 *     $patches = $module->getAvailablePatches();
 * }
 *
 * // Install a patch
 * $result = $module->install($patchHistoryId);
 *
 * @package PatchModule
 * @version 1.6.2
 * @license MIT
 */
class PatchModule
{
    /** @var DatabaseAdapterInterface */
    private DatabaseAdapterInterface $database;

    /** @var HttpClientInterface */
    private HttpClientInterface $httpClient;

    /** @var ArchiveAdapterInterface */
    private ArchiveAdapterInterface $archiveAdapter;

    /** @var VersionResolverInterface */
    private VersionResolverInterface $versionResolver;

    /** @var BackupAdapterInterface|null */
    private ?BackupAdapterInterface $backupAdapter;

    /** @var SignatureVerifierInterface|null */
    private ?SignatureVerifierInterface $signatureVerifier;

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

    /** @var AuthAdapterInterface|null */
    private ?AuthAdapterInterface $authAdapter;

    /** @var CsrfAdapterInterface|null */
    private ?CsrfAdapterInterface $csrfAdapter;

    /** @var TranslatorInterface|null */
    private ?TranslatorInterface $translator;

    /** @var AdminActions|null */
    private ?AdminActions $adminActions = null;

    /** @var string Normalized admin UI base URL (empty when admin UI is not configured) */
    private readonly string $baseUrl;

    /** @var PatchChecker */
    private PatchChecker $checker;

    /** @var PatchDownloader */
    private PatchDownloader $downloader;

    /** @var PatchInstaller */
    private PatchInstaller $installer;

    /** @var PatchFileManager */
    private PatchFileManager $fileManager;

    /** @var PatchMigrator */
    private PatchMigrator $migrator;

    /** @var ProgressTracker */
    private ProgressTracker $progressTracker;

    /** @var MaintenanceMode */
    private MaintenanceMode $maintenanceMode;

    /** @var array Original configuration */
    private array $config;

    /**
     * Initialize the patch module
     *
     * @param array $config Configuration array:
     *   Required:
     *   - get_pdo: callable():PDO       — Lazy PDO factory (OR 'pdo' OR 'database_adapter')
     *   - patch_server_url: string       — Patch server base URL (no /patches/check suffix)
     *   - license_key: string|callable   — License key or callable returning it
     *   - version_resolver: VersionResolverInterface — Version get/set implementation
     *   - root_path: string              — Project root directory
     *   - temp_path: string              — Writable temp directory
     *
     *   Optional adapters:
     *   - backup_adapter: BackupAdapterInterface — Backup service (null = skip backup)
     *   - archive_adapter: ArchiveAdapterInterface — Archive extractor (null = auto-detect)
     *   - http_client: HttpClientInterface — HTTP client (null = CurlHttpClient)
     *   - signature_verifier: SignatureVerifierInterface — Patch signature verifier (null = OpenSslSignatureVerifier)
     *   - logger: LoggerInterface — Logger (null = silent)
     *   - auth_adapter: AuthAdapterInterface — Required for admin UI (null = admin UI disabled)
     *   - csrf_adapter: CsrfAdapterInterface — Required for admin UI (null = admin UI disabled)
     *   - base_url: string — Admin UI base path, e.g. '/admin/patch-management' (required when
     *                        auth_adapter and csrf_adapter are set); must start with '/', same-origin
     *                        path only, no trailing slash, no '..', '?', '#', '//', or whitespace
     *   - translator: TranslatorInterface — Optional for admin UI (null = module uses its own locale)
     *
     *   Optional settings:
     *   - check_cache_hours: int        — Cache duration in hours (default: 6)
     *   - min_disk_space: int           — Minimum free bytes (default: 200 MB)
     *   - api_timeout: int              — API request timeout in seconds (default: 30)
     *   - download_timeout: int         — Download timeout in seconds (default: 300)
     *   - default_language: string      — Maintenance page language (default: 'en')
     *   - expected_public_key_pem: string — Pinned server public key PEM; when set, patches
     *                                       whose public_key does not match are rejected (default: null)
     *   - license_verify_callback: callable — Invoked before download to refresh the server-side
     *                                          license check window; also used for a single retry
     *                                          when the server rejects a download as stale (default: null)
     *   - cache_paths_to_clear: string[] — Absolute paths to compiled-cache directories (e.g. Twig)
     *                                      cleared after each file-mutation step and after rollback
     *                                      (default: [])
     *   - keep_last_snapshots: int      — Number of completed installs whose snapshot and DB backup
     *                                     are retained for later rollback; older ones are pruned
     *                                     (default: 3)
     *
     * @throws \InvalidArgumentException If required configuration is missing
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig($config);
        $rawUrl = (string) ($config['base_url'] ?? '');
        if ($rawUrl !== '' && strlen($rawUrl) > 1 && str_ends_with($rawUrl, '/')) {
            $rawUrl = rtrim($rawUrl, '/');
        }
        $this->baseUrl = $rawUrl;
        $this->initializeAdapters($config);
        $this->initializeComponents($config);
    }

    // =========================================================================
    // Patch Checking
    // =========================================================================

    /**
     * Check for available updates from the patch server
     *
     * @param bool $forceCheck Skip cache and always contact the server
     * @return array{available: bool, count: ?int, patches: ?array, version: ?string, current_version: string, error: ?string}
     */
    public function checkForUpdates(bool $forceCheck = false): array
    {
        $licenseKey = $this->resolveLicenseKey();
        return $this->checker->checkForUpdates($licenseKey, $forceCheck);
    }

    /**
     * Get all available (non-installed, non-dismissed) patches
     *
     * @return array List of available patch data objects sorted by released_at ascending
     */
    public function getAvailablePatches(): array
    {
        return $this->checker->getAvailablePatches();
    }

    /**
     * Get the first (oldest) available patch
     *
     * @return array|null First available patch or null
     */
    public function getAvailablePatch(): ?array
    {
        return $this->checker->getAvailablePatch();
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
        $this->checker->dismissPatch($version, $userId);
    }

    /**
     * Dismiss all available patch notifications
     *
     * @param int|null $userId User performing the dismissal
     * @return void
     */
    public function dismissAllPatches(?int $userId = null): void
    {
        $this->checker->dismissAllPatches($userId);
    }

    // =========================================================================
    // Installation
    // =========================================================================

    /**
     * Install a patch end-to-end
     *
     * @param int      $patchHistoryId patch_history record ID
     * @param bool     $createBackup   Whether to create a backup before installing
     * @param int|null $userId         User performing the installation
     * @param string|null $language    Language code for the maintenance page (null = module default)
     * @return array{success: bool, error: ?string}
     */
    public function install(
        int $patchHistoryId,
        bool $createBackup = true,
        ?int $userId = null,
        ?string $language = null
    ): array {
        $licenseKey = $this->resolveLicenseKey();
        return $this->installer->install($patchHistoryId, $licenseKey, $createBackup, $userId, $language);
    }

    /**
     * Rollback a failed patch installation
     *
     * @param int      $patchHistoryId patch_history record ID
     * @param int|null $userId         User performing the rollback (null for automated rollbacks)
     * @return array{success: bool, error: ?string}
     */
    public function rollback(int $patchHistoryId, ?int $userId = null): array
    {
        return $this->installer->rollback($patchHistoryId, $userId);
    }

    // =========================================================================
    // Progress Tracking
    // =========================================================================

    /**
     * Set the progress file path for install tracking
     *
     * @param string $path Absolute path to the progress JSON file
     * @return void
     */
    public function setProgressFile(string $path): void
    {
        $this->progressTracker->setProgressFile($path);
    }

    /**
     * Read install progress from file
     *
     * @param string $token Progress token
     * @return array|null Progress data or null if not found
     */
    public function getInstallProgress(string $token): ?array
    {
        return $this->progressTracker->getInstallProgress($token);
    }

    /**
     * Delete the progress file for a given token
     *
     * @param string $token Progress token
     * @return void
     */
    public function deleteProgressFile(string $token): void
    {
        $this->progressTracker->deleteProgressFile($token);
    }

    // =========================================================================
    // Maintenance Mode
    // =========================================================================

    /**
     * Enable maintenance mode during patch installation
     *
     * @param string $version Version being installed
     * @param string|null $language Override language for the maintenance page
     * @return void
     */
    public function enableMaintenanceMode(string $version, ?string $language = null): void
    {
        $this->maintenanceMode->enable($version, $language);
        $this->log("Patch maintenance mode enabled for v{$version}", 'INFO');
    }

    /**
     * Disable maintenance mode
     *
     * @return void
     */
    public function disableMaintenanceMode(): void
    {
        $this->maintenanceMode->disable();
        $this->log("Patch maintenance mode disabled", 'INFO');
    }

    /**
     * Check if maintenance mode is active
     *
     * @return bool
     */
    public function isMaintenanceModeActive(): bool
    {
        return $this->maintenanceMode->isActive();
    }

    /**
     * Get the maintenance mode flag file path
     *
     * @return string Absolute path to the flag file
     */
    public function getMaintenanceFlagPath(): string
    {
        return $this->maintenanceMode->getFlagFilePath();
    }

    // =========================================================================
    // History
    // =========================================================================

    /**
     * Get patch installation history
     *
     * @return array List of patch history records
     */
    public function getHistory(): array
    {
        return $this->installer->getHistory();
    }

    /**
     * Get a single patch history record by ID
     *
     * @param int $id Record ID
     * @return array|null
     */
    public function getHistoryRecord(int $id): ?array
    {
        return $this->database->getHistoryRecord($id);
    }

    /**
     * Find a patch history record by version
     *
     * @param string $version Version string
     * @param array $statuses Status filter
     * @return array|null
     */
    public function findHistoryByVersion(string $version, array $statuses = ['available', 'downloading']): ?array
    {
        return $this->database->findHistoryByVersion($version, $statuses);
    }

    // =========================================================================
    // Views
    // =========================================================================

    /**
     * Render a module view template
     *
     * @param string $viewName View name: 'maintenance'
     * @param array $data Variables to extract into the view scope
     * @return string Rendered HTML
     */
    public function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/views/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return '';
        }

        extract($data);
        ob_start();
        include $viewPath;
        return ob_get_clean() ?: '';
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the database adapter
     *
     * @return DatabaseAdapterInterface
     */
    public function getDatabase(): DatabaseAdapterInterface
    {
        return $this->database;
    }

    /**
     * Get the progress tracker
     *
     * @return ProgressTracker
     */
    public function getProgressTracker(): ProgressTracker
    {
        return $this->progressTracker;
    }

    /**
     * Get the maintenance mode manager
     *
     * @return MaintenanceMode
     */
    public function getMaintenanceMode(): MaintenanceMode
    {
        return $this->maintenanceMode;
    }

    /**
     * Get the version resolver
     *
     * @return VersionResolverInterface
     */
    public function getVersionResolver(): VersionResolverInterface
    {
        return $this->versionResolver;
    }

    /**
     * Get the normalized admin UI base URL path
     *
     * Returns the validated and trailing-slash-stripped value of the 'base_url'
     * config key. Returns an empty string when the admin UI is not configured
     * (no auth_adapter or csrf_adapter). The returned value is safe to use
     * directly as an HTML attribute value after htmlspecialchars().
     *
     * @return string Same-origin path without trailing slash, e.g. '/admin/patch-management'
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the admin actions handler for the patch management UI
     *
     * Returns null when auth_adapter or csrf_adapter is not configured,
     * in which case the admin UI is not available.
     *
     * @return AdminActions|null
     */
    public function getAdminActions(): ?AdminActions
    {
        if ($this->authAdapter === null || $this->csrfAdapter === null) {
            return null;
        }

        if ($this->adminActions === null) {
            $this->adminActions = new AdminActions(
                $this,
                $this->authAdapter,
                $this->csrfAdapter,
                $this->config['temp_path'],
                $this->config['root_path'],
                $this->translator,
            );
        }

        return $this->adminActions;
    }

    /**
     * Check whether the admin patch-management UI is available
     *
     * Returns enabled=false when auth_adapter or csrf_adapter are not configured,
     * or when patch_server_url is empty. The reason field gives a human-readable
     * explanation suitable for showing a disabled-state banner.
     *
     * @return array{enabled: bool, reason: string}
     */
    public function isAvailable(): array
    {
        if (empty($this->config['patch_server_url'])) {
            return ['enabled' => false, 'reason' => 'patch_server_url not configured'];
        }

        if ($this->authAdapter === null || $this->csrfAdapter === null) {
            return ['enabled' => false, 'reason' => 'auth_adapter and csrf_adapter required for admin UI'];
        }

        return ['enabled' => true, 'reason' => ''];
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    /**
     * Validate required configuration
     *
     * @param array $config Configuration array
     * @return void
     * @throws \InvalidArgumentException If required keys are missing
     */
    private function validateConfig(array $config): void
    {
        if (!isset($config['get_pdo']) && !isset($config['pdo']) && !isset($config['database_adapter'])) {
            throw new \InvalidArgumentException(
                'PatchModule requires "get_pdo" callable, "pdo" instance, or "database_adapter"'
            );
        }

        if (empty($config['patch_server_url'])) {
            throw new \InvalidArgumentException('PatchModule requires "patch_server_url"');
        }

        if (!isset($config['license_key'])) {
            throw new \InvalidArgumentException('PatchModule requires "license_key" (string or callable)');
        }

        if (!isset($config['version_resolver']) || !$config['version_resolver'] instanceof VersionResolverInterface) {
            throw new \InvalidArgumentException('PatchModule requires "version_resolver" (VersionResolverInterface)');
        }

        if (empty($config['root_path'])) {
            throw new \InvalidArgumentException('PatchModule requires "root_path"');
        }

        if (empty($config['temp_path'])) {
            throw new \InvalidArgumentException('PatchModule requires "temp_path"');
        }

        $this->validateBaseUrl($config);
    }

    /**
     * Validate the base_url config key when admin UI adapters are configured
     *
     * Enforces that the value is a safe, same-origin absolute path so it can be
     * used as the JS fetch() prefix without risk of cross-origin requests or
     * log-injection. Only fires when at least one of auth_adapter or csrf_adapter
     * is present in config; passes silently when neither is set.
     *
     * @param array $config Configuration array
     * @return void
     * @throws \InvalidArgumentException If base_url is missing or contains an unsafe value
     */
    private function validateBaseUrl(array $config): void
    {
        $hasAdminAdapters = !empty($config['auth_adapter']) || !empty($config['csrf_adapter']);
        if (!$hasAdminAdapters) {
            return;
        }

        $url = $config['base_url'] ?? '';
        if (!is_string($url) || $url === '') {
            throw new \InvalidArgumentException(
                'PatchModule requires "base_url" when admin UI adapters are configured (string starting with "/")'
            );
        }
        if ($url[0] !== '/') {
            throw new \InvalidArgumentException(
                'PatchModule requires "base_url" (string starting with "/")'
            );
        }
        if (strlen($url) > 1 && $url[1] === '/') {
            throw new \InvalidArgumentException(
                'PatchModule "base_url" must be a same-origin path; protocol-relative ("//...") and absolute ("scheme://...") URLs are rejected'
            );
        }
        if (str_contains($url, '://')) {
            throw new \InvalidArgumentException(
                'PatchModule "base_url" must be a same-origin path; protocol-relative ("//...") and absolute ("scheme://...") URLs are rejected'
            );
        }
        if (str_contains($url, '..')) {
            throw new \InvalidArgumentException(
                'PatchModule "base_url" must not contain path traversal sequences ("..")'
            );
        }
        if (str_contains($url, '%')) {
            throw new \InvalidArgumentException(
                'PatchModule "base_url" must not contain percent-encoded sequences'
            );
        }
        // \x00-\x1F = C0 control characters, \x7F = DEL, \x80-\xFF = high-byte UTF-8
        if (preg_match('/[?#\s\x00-\x1F\x7F\x80-\xFF]/', $url)) {
            throw new \InvalidArgumentException(
                'PatchModule "base_url" must not contain "?", "#", whitespace, or non-ASCII characters'
            );
        }
        if (preg_match('#//#', substr($url, 1))) {
            throw new \InvalidArgumentException(
                'PatchModule "base_url" must not contain consecutive slashes ("//")'
            );
        }
    }

    /**
     * Initialize adapters from configuration
     *
     * @param array $config Configuration array
     * @return void
     */
    private function initializeAdapters(array $config): void
    {
        // Database adapter
        if (isset($config['database_adapter']) && $config['database_adapter'] instanceof DatabaseAdapterInterface) {
            $this->database = $config['database_adapter'];
        } elseif (isset($config['get_pdo']) && is_callable($config['get_pdo'])) {
            $this->database = new CallableAdapter($config['get_pdo']);
        } elseif (isset($config['pdo']) && $config['pdo'] instanceof PDO) {
            $this->database = new PdoAdapter($config['pdo']);
        }

        // HTTP client
        $this->httpClient = $config['http_client'] ?? new CurlHttpClient();

        // Archive adapter (auto-detect if not provided)
        if (isset($config['archive_adapter']) && $config['archive_adapter'] instanceof ArchiveAdapterInterface) {
            $this->archiveAdapter = $config['archive_adapter'];
        } elseif (ExecTarAdapter::isAvailable()) {
            $this->archiveAdapter = new ExecTarAdapter();
        } else {
            $this->archiveAdapter = new PharTarAdapter();
        }

        // Version resolver
        $this->versionResolver = $config['version_resolver'];

        // Optional adapters
        $this->backupAdapter = $config['backup_adapter'] ?? null;
        $this->logger = $config['logger'] ?? null;

        // Signature verifier: use provided adapter or default to OpenSSL implementation
        if (isset($config['signature_verifier']) && $config['signature_verifier'] instanceof SignatureVerifierInterface) {
            $this->signatureVerifier = $config['signature_verifier'];
        } else {
            $this->signatureVerifier = new OpenSslSignatureVerifier();
        }

        // Admin UI adapters
        $this->authAdapter = $config['auth_adapter'] ?? null;
        $this->csrfAdapter = $config['csrf_adapter'] ?? null;
        $this->translator  = $config['translator'] ?? null;
    }

    /**
     * Initialize internal components
     *
     * @param array $config Configuration array
     * @return void
     */
    private function initializeComponents(array $config): void
    {
        $serverUrl = $config['patch_server_url'];
        $tempPath = $config['temp_path'];
        $rootPath = $config['root_path'];
        $checkCacheHours = $config['check_cache_hours'] ?? 6;
        $apiTimeout = $config['api_timeout'] ?? 30;
        $downloadTimeout = $config['download_timeout'] ?? 300;
        $minDiskSpace = $config['min_disk_space'] ?? 209715200; // 200 MB
        $defaultLanguage = $config['default_language'] ?? 'en';

        $expectedPublicKeyPem = $config['expected_public_key_pem'] ?? null;
        $licenseVerifyCallback = isset($config['license_verify_callback']) && is_callable($config['license_verify_callback'])
            ? $config['license_verify_callback']
            : null;

        $this->progressTracker = new ProgressTracker($tempPath);
        $this->maintenanceMode = new MaintenanceMode($tempPath, $defaultLanguage);

        $this->checker = new PatchChecker(
            $this->database,
            $this->httpClient,
            $this->versionResolver,
            $serverUrl,
            $checkCacheHours,
            $apiTimeout,
            $this->logger,
            $this->signatureVerifier,
            $expectedPublicKeyPem
        );

        $this->downloader = new PatchDownloader(
            $this->httpClient,
            $this->database,
            $serverUrl,
            $tempPath,
            $downloadTimeout,
            $this->logger
        );

        $this->fileManager = new PatchFileManager(
            $this->archiveAdapter,
            $tempPath,
            $rootPath,
            $this->logger
        );

        $this->migrator = new PatchMigrator(
            $this->database,
            $this->logger
        );

        $cachePathsToClear  = $config['cache_paths_to_clear'] ?? [];
        $keepLastSnapshots  = (int) ($config['keep_last_snapshots'] ?? 3);

        $this->installer = new PatchInstaller(
            $this->database,
            $this->checker,
            $this->downloader,
            $this->fileManager,
            $this->migrator,
            $this->progressTracker,
            $this->versionResolver,
            $rootPath,
            $minDiskSpace,
            $this->backupAdapter,
            $this->logger,
            $licenseVerifyCallback,
            $this->maintenanceMode,
            is_array($cachePathsToClear) ? $cachePathsToClear : [],
            $keepLastSnapshots
        );
    }

    /**
     * Resolve the license key from configuration
     *
     * @return string License key
     */
    private function resolveLicenseKey(): string
    {
        $key = $this->config['license_key'];
        if (is_callable($key)) {
            return (string) $key();
        }
        return (string) $key;
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
}