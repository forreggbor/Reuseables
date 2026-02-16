<?php

declare(strict_types=1);

namespace PatchModule;

use PatchModule\Adapters\Archive\ExecTarAdapter;
use PatchModule\Adapters\Archive\PharTarAdapter;
use PatchModule\Adapters\Database\CallableAdapter;
use PatchModule\Adapters\Database\PdoAdapter;
use PatchModule\Adapters\Http\CurlHttpClient;
use PatchModule\Contracts\ArchiveAdapterInterface;
use PatchModule\Contracts\BackupAdapterInterface;
use PatchModule\Contracts\DatabaseAdapterInterface;
use PatchModule\Contracts\HttpClientInterface;
use PatchModule\Contracts\LoggerInterface;
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
 * @version 1.0.0
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

    /** @var LoggerInterface|null */
    private ?LoggerInterface $logger;

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
     *   - logger: LoggerInterface — Logger (null = silent)
     *
     *   Optional settings:
     *   - check_cache_hours: int  — Cache duration in hours (default: 6)
     *   - min_disk_space: int     — Minimum free bytes (default: 200 MB)
     *   - api_timeout: int        — API request timeout in seconds (default: 30)
     *   - download_timeout: int   — Download timeout in seconds (default: 300)
     *   - default_language: string — Maintenance page language (default: 'en')
     *
     * @throws \InvalidArgumentException If required configuration is missing
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig($config);
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
     * @param int $patchHistoryId patch_history record ID
     * @param bool $createBackup Whether to create a backup before installing
     * @param int|null $userId User performing the installation
     * @return array{success: bool, error: ?string}
     */
    public function install(int $patchHistoryId, bool $createBackup = true, ?int $userId = null): array
    {
        $licenseKey = $this->resolveLicenseKey();
        return $this->installer->install($patchHistoryId, $licenseKey, $createBackup, $userId);
    }

    /**
     * Rollback a failed patch installation
     *
     * @param int $patchHistoryId patch_history record ID
     * @return array{success: bool, error: ?string}
     */
    public function rollback(int $patchHistoryId): array
    {
        return $this->installer->rollback($patchHistoryId);
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

        $this->progressTracker = new ProgressTracker($tempPath);
        $this->maintenanceMode = new MaintenanceMode($tempPath, $defaultLanguage);

        $this->checker = new PatchChecker(
            $this->database,
            $this->httpClient,
            $this->versionResolver,
            $serverUrl,
            $checkCacheHours,
            $apiTimeout,
            $this->logger
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
            $this->logger
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