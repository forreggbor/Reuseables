<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Main facade for framework-agnostic license validation and feature gating.
 */

declare(strict_types=1);

namespace LicenseModule;

use LicenseModule\Adapters\Database\CallableAdapter;
use LicenseModule\Adapters\Database\PdoAdapter;
use LicenseModule\Adapters\Http\CurlHttpClient;
use LicenseModule\Adapters\Session\NativeSessionAdapter;
use LicenseModule\Contracts\DatabaseAdapterInterface;
use LicenseModule\Contracts\HttpClientInterface;
use LicenseModule\Contracts\SessionAdapterInterface;
use LicenseModule\Contracts\TranslatorInterface;
use PDO;

/**
 * LicenseModule - Framework-agnostic license validation and feature gating
 *
 * Main facade providing a simple API for license validation, status checks,
 * and tier/addon-based feature gating.
 *
 * @example
 * // Minimal setup with PDO callable
 * $license = new LicenseModule([
 *     'get_pdo' => fn() => \App\Core\Database::getInstance()->getConnection(),
 * ]);
 *
 * // Check license status
 * if ($license->isActive()) {
 *     // Normal operation
 * }
 *
 * // Feature gating
 * if ($license->hasModule('reports')) {
 *     // Show reports feature
 * }
 *
 * // Middleware integration
 * $check = $license->checkMiddleware();
 * if ($check !== null) {
 *     http_response_code($check['http_code']);
 *     echo $check['view'];
 *     exit;
 * }
 */
class LicenseModule
{
    /** Default license server URL */
    private const DEFAULT_SERVER_URL = 'https://lm.patrikmol.com/api/v1/licenses/verify';

    private LicenseValidator $validator;
    private FeatureGate $featureGate;
    private SessionAdapterInterface $session;
    private DatabaseAdapterInterface $database;
    private array $config;
    private string $locale;
    private ?TranslatorInterface $translator = null;

    /**
     * Initialize the license module
     *
     * @param array $config Configuration array:
     *   - get_pdo: callable():PDO - Required. Returns PDO connection
     *   - server_url: string - License server URL (optional, has default)
     *   - tiers: array - Custom tier configuration (optional, uses JupitERP defaults)
     *   - addons: array - Custom addon configuration (optional, uses JupitERP defaults)
     *   - session_adapter: SessionAdapterInterface - Custom session adapter (optional)
     *   - http_client: HttpClientInterface - Custom HTTP client (optional)
     *   - log_callback: callable - Logging callback fn(string $message, string $level)
     *   - locale: string - Locale code for built-in translations (default 'en_US')
     *   - translator: TranslatorInterface - Optional host-side translator bridge
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->locale = $config['locale'] ?? 'en_US';
        if (isset($config['translator']) && $config['translator'] instanceof TranslatorInterface) {
            $this->translator = $config['translator'];
        }
        $this->initializeAdapters($config);
        $this->initializeValidator($config);
        $this->initializeFeatureGate($config);
    }

    /**
     * Initialize adapters from configuration
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
        } else {
            throw new \InvalidArgumentException(
                'LicenseModule requires either "get_pdo" callable, "pdo" instance, or "database_adapter"'
            );
        }

        // Session adapter
        if (isset($config['session_adapter']) && $config['session_adapter'] instanceof SessionAdapterInterface) {
            $this->session = $config['session_adapter'];
        } else {
            $this->session = new NativeSessionAdapter();
        }
    }

    /**
     * Initialize the validator
     */
    private function initializeValidator(array $config): void
    {
        $httpClient = $config['http_client'] ?? new CurlHttpClient();
        $serverUrl = $config['server_url'] ?? self::DEFAULT_SERVER_URL;
        $logCallback = $config['log_callback'] ?? null;

        $this->validator = new LicenseValidator(
            $this->database,
            $httpClient,
            $serverUrl,
            $logCallback
        );
    }

    /**
     * Initialize the feature gate
     */
    private function initializeFeatureGate(array $config): void
    {
        $this->featureGate = new FeatureGate(
            fn() => $this->getParsedFeatures(),
            $config['tiers'] ?? null,
            $config['addons'] ?? null
        );
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate license with the license server
     *
     * @param string $licenseKey License key to validate
     * @param string $domain Domain to validate against
     * @return array Validation result with keys: success, status, message, data
     */
    public function validate(string $licenseKey, string $domain): array
    {
        $result = $this->validator->validate($licenseKey, $domain);

        // Update session cache
        $this->session->set('status', $result['status']);

        // Clear feature gate cache
        $this->featureGate->clearCache();

        return $result;
    }

    /**
     * Check if periodic validation is due
     *
     * @return bool
     */
    public function isValidationDue(): bool
    {
        return $this->validator->isValidationDue();
    }

    // =========================================================================
    // Status Checks
    // =========================================================================

    /**
     * Get current license status
     *
     * @return string One of LicenseStatus constants
     */
    public function getStatus(): string
    {
        return $this->validator->getCurrentStatus();
    }

    /**
     * Check if license is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return LicenseStatus::isActive($this->getStatus());
    }

    /**
     * Check if license is in server-side grace period
     *
     * The license has expired on the server but is within the configured grace window.
     * The system remains fully operational during this period.
     *
     * @return bool
     */
    public function isInGracePeriod(): bool
    {
        return LicenseStatus::isGrace($this->getStatus());
    }

    /**
     * Check if license is expired (read-only mode)
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->getStatus() === LicenseStatus::EXPIRED;
    }

    /**
     * Check if license is suspended
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->getStatus() === LicenseStatus::SUSPENDED;
    }

    /**
     * Check if license is invalid
     *
     * @return bool
     */
    public function isInvalid(): bool
    {
        return $this->getStatus() === LicenseStatus::INVALID;
    }

    /**
     * Check if license status blocks all access
     *
     * @return bool
     */
    public function isBlocked(): bool
    {
        return LicenseStatus::isBlocked($this->getStatus());
    }

    // =========================================================================
    // Feature Gating
    // =========================================================================

    /**
     * Check if a module is enabled
     *
     * @param string $module Module identifier
     * @return bool
     */
    public function hasModule(string $module): bool
    {
        return $this->featureGate->hasModule($module);
    }

    /**
     * Get all enabled modules
     *
     * @return string[]
     */
    public function getEnabledModules(): array
    {
        return $this->featureGate->getEnabledModules();
    }

    /**
     * Get current tier information
     *
     * @return array|null Tier object {slug, name, level, description} or null for legacy license
     */
    public function getTier(): ?array
    {
        return $this->featureGate->getTier();
    }

    /**
     * Get current tier level
     *
     * @return int Tier level (0 if legacy/invalid)
     */
    public function getTierLevel(): int
    {
        return $this->featureGate->getTierLevel();
    }

    /**
     * Check if an addon is enabled
     *
     * @param string $addonKey Addon feature key
     * @return bool
     */
    public function hasAddon(string $addonKey): bool
    {
        return $this->featureGate->hasAddon($addonKey);
    }

    /**
     * Get enabled addon feature keys
     *
     * @return string[]
     */
    public function getEnabledAddons(): array
    {
        return $this->featureGate->getEnabledAddons();
    }

    /**
     * Get full addon rows enabled by this license.
     * Each row: feature_key, name, slug, description.
     *
     * @return array<int, array{feature_key: string, name: string, slug: string, description: string|null}>
     */
    public function getAddons(): array
    {
        return $this->featureGate->getAddons();
    }

    /**
     * Get module slugs enabled by the current tier level only (excluding addon modules).
     *
     * @return string[]
     */
    public function getTierModules(): array
    {
        return $this->featureGate->getTierModules();
    }

    // =========================================================================
    // Middleware Helper
    // =========================================================================

    /**
     * Check license for middleware integration
     *
     * Returns null if license is OK, or an array with blocking information.
     *
     * @return array|null Null if OK, or array with: status, http_code, view, is_json
     */
    public function checkMiddleware(): ?array
    {
        $status = $this->getStatus();

        if (LicenseStatus::isActive($status)) {
            return null;
        }

        if (LicenseStatus::isReadOnly($status)) {
            return [
                'status' => $status,
                'http_code' => 403,
                'view' => $this->renderView('expired'),
                'is_json' => false,
            ];
        }

        // Blocked (invalid or suspended)
        return [
            'status' => $status,
            'http_code' => 403,
            'view' => $this->renderView('suspended'),
            'is_json' => false,
        ];
    }

    /**
     * Get JSON response for API endpoints when license is not valid
     *
     * @return array|null Null if OK, or array with error response
     */
    public function checkMiddlewareJson(): ?array
    {
        $status = $this->getStatus();

        if (LicenseStatus::isActive($status)) {
            return null;
        }

        return [
            'error' => true,
            'license_status' => $status,
            'message' => $this->getStatusMessage($status),
        ];
    }

    // =========================================================================
    // License Info
    // =========================================================================

    /**
     * Get raw license information from database
     *
     * @return array|null
     */
    public function getLicenseInfo(): ?array
    {
        return $this->validator->getLicenseInfo();
    }

    /**
     * Get days until license expiration
     *
     * @return int|null Days remaining or null if no expiration date
     */
    public function getDaysUntilExpiration(): ?int
    {
        $licenseInfo = $this->getLicenseInfo();

        if ($licenseInfo === null || empty($licenseInfo['expires_at'])) {
            return null;
        }

        $expiryTime = strtotime($licenseInfo['expires_at']);
        $daysRemaining = ($expiryTime - time()) / 86400;

        return (int) ceil($daysRemaining);
    }

    /**
     * Get the grace period expiry date
     *
     * @return string|null Grace expiry datetime string or null if not in grace period
     */
    public function getGraceExpiresAt(): ?string
    {
        $licenseInfo = $this->getLicenseInfo();

        if ($licenseInfo === null || empty($licenseInfo['grace_expires_at'])) {
            return null;
        }

        return $licenseInfo['grace_expires_at'];
    }

    /**
     * Get days until grace period expiration
     *
     * @return int|null Days remaining in grace period or null if not in grace period
     */
    public function getDaysUntilGraceExpiration(): ?int
    {
        $graceExpiresAt = $this->getGraceExpiresAt();

        if ($graceExpiresAt === null) {
            return null;
        }

        $graceExpiryTime = strtotime($graceExpiresAt);
        $daysRemaining = ($graceExpiryTime - time()) / 86400;

        return (int) ceil($daysRemaining);
    }

    /**
     * Get the most recent license_info row regardless of status.
     * Unlike getLicenseInfo(), this includes suspended and invalid licenses.
     *
     * @return array|null
     */
    public function getLatestLicenseInfo(): ?array
    {
        return $this->database->getLatestLicenseInfo();
    }

    /**
     * Get validation history rows in reverse chronological order.
     *
     * @param int $limit Maximum number of rows to return
     * @return array
     */
    public function getValidationHistory(int $limit = 20): array
    {
        return $this->validator->getValidationHistory($limit);
    }

    // =========================================================================
    // Locale & Translation
    // =========================================================================

    /**
     * Get the configured locale code.
     *
     * @return string Locale code, e.g. 'en_US' or 'hu_HU'
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Translate a TEXT_* key with optional positional parameters.
     *
     * Delegates to the injected TranslatorInterface when available; otherwise loads
     * the module's own locale PHP-array. Falls back to en_US if the configured locale
     * file is missing a key.
     *
     * @param string $key       Translation key
     * @param mixed  ...$params Positional values for %s/%d placeholders
     * @return string
     */
    private function t(string $key, mixed ...$params): string
    {
        if ($this->translator !== null) {
            return $this->translator->t($key, $params);
        }

        static $cache = [];
        static $fallbackCache = null;

        $locale = $this->locale;

        if (!isset($cache[$locale])) {
            $path = __DIR__ . '/locale/' . $locale . '/messages.php';
            if (file_exists($path)) {
                $loaded = require $path;
                $cache[$locale] = is_array($loaded) ? $loaded : [];
            } else {
                $cache[$locale] = [];
            }
        }

        if ($fallbackCache === null) {
            $path = __DIR__ . '/locale/en_US/messages.php';
            if (file_exists($path)) {
                $loaded = require $path;
                $fallbackCache = is_array($loaded) ? $loaded : [];
            } else {
                $fallbackCache = [];
            }
        }

        $string = $cache[$locale][$key] ?? $fallbackCache[$key] ?? $key;

        if ($params !== [] && (str_contains($string, '%s') || str_contains($string, '%d'))) {
            return vsprintf($string, $params);
        }

        return $string;
    }

    // =========================================================================
    // Admin Page
    // =========================================================================

    /**
     * Render the license administration page as an HTML fragment.
     * The host application embeds this inside its own admin layout.
     *
     * Security note: this method does NOT enforce authentication, ACL, or CSRF —
     * the host is responsible for protecting the route that calls this.
     *
     * @param array $options {
     *   @type string               $locale          Per-call locale override
     *   @type TranslatorInterface  $translator      Per-call translator override
     *   @type string               $asset_base_url  URL prefix for CSS/JS assets (required for assets to load)
     *   @type string|null          $validate_url    POST endpoint; if null Validate button is hidden
     *   @type string|null          $csrf_token      Token for AJAX validation call
     *   @type string               $renew_url       Renew link (default https://lm.patrikmol.com)
     *   @type array                $module_names    slug→display-name map for tier module list
     *   @type string               $date_format     PHP date() format (default 'Y-m-d')
     *   @type string               $datetime_format PHP date() format (default 'Y-m-d H:i:s')
     *   @type int                  $history_limit   Max history rows (default 20)
     * }
     * @return string Rendered HTML fragment, or empty string if the view file is not yet present
     */
    public function renderAdminPage(array $options = []): string
    {
        $viewPath = __DIR__ . '/views/admin/license.php';

        if (!file_exists($viewPath)) {
            return '';
        }

        // Resolve per-call locale
        $callLocale = $options['locale'] ?? $this->locale;

        // Build $t closure
        if (isset($options['translator']) && $options['translator'] instanceof TranslatorInterface) {
            $callTranslator = $options['translator'];
            $t = static function (string $key, mixed ...$params) use ($callTranslator): string {
                return $callTranslator->t($key, $params);
            };
        } else {
            $savedLocale = $this->locale;
            $this->locale = $callLocale;
            $t = fn(string $key, mixed ...$params): string => $this->t($key, ...$params);
        }

        // Gather view data
        $license          = $this->getLatestLicenseInfo();
        $tier             = $this->getTier();
        $addons           = $this->getAddons();
        $tierModules      = $this->getTierModules();
        $history          = $this->getValidationHistory((int) ($options['history_limit'] ?? 20));
        $daysRemaining    = $this->getDaysUntilExpiration();
        $graceDaysRemaining = $this->getDaysUntilGraceExpiration();

        // Derive status from the raw (unfiltered) row so suspended/invalid licenses
        // display correctly. Applies the same expiry checks as getCurrentStatus().
        if ($license === null) {
            $status = LicenseStatus::INVALID;
        } else {
            $rawStatus = $license['status'] ?? LicenseStatus::INVALID;
            if ($rawStatus === LicenseStatus::GRACE
                && !empty($license['grace_expires_at'])
                && strtotime($license['grace_expires_at']) < time()
            ) {
                $status = LicenseStatus::EXPIRED;
            } elseif (!empty($license['expires_at']) && strtotime($license['expires_at']) < time()
                && !in_array($rawStatus, [LicenseStatus::SUSPENDED, LicenseStatus::INVALID], true)
            ) {
                $status = LicenseStatus::EXPIRED;
            } else {
                $status = $rawStatus;
            }
        }

        try {
            ob_start();
            try {
                include $viewPath;
                return ob_get_clean() ?: '';
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        } finally {
            if (isset($savedLocale)) {
                $this->locale = $savedLocale;
            }
        }
    }

    // =========================================================================
    // Internal Helpers
    // =========================================================================

    /**
     * Get parsed features from license info
     *
     * @return array|null Features array or null for legacy license
     */
    private function getParsedFeatures(): ?array
    {
        $licenseInfo = $this->validator->getLicenseInfo();

        if ($licenseInfo === null || empty($licenseInfo['features'])) {
            return null;
        }

        $features = json_decode($licenseInfo['features'], true);

        // Legacy format: ['all'] or similar array
        if (is_array($features) && !isset($features['tier'])) {
            return null;
        }

        return [
            'tier' => $features['tier'] ?? null,
            'addons' => $features['addons'] ?? [],
        ];
    }

    /**
     * Render a view file
     *
     * @param string $viewName View name (without extension)
     * @return string Rendered HTML
     */
    private function renderView(string $viewName): string
    {
        $viewPath = __DIR__ . '/views/' . $viewName . '.php';

        if (!file_exists($viewPath)) {
            return '<h1>License Error</h1><p>Status: ' . htmlspecialchars($this->getStatus()) . '</p>';
        }

        ob_start();
        include $viewPath;

        return ob_get_clean() ?: '';
    }

    /**
     * Get status message for display
     *
     * @param string $status License status
     * @return string Human-readable message
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            LicenseStatus::GRACE => function_exists('_') ? _('LICENSE_GRACE_MESSAGE') : 'License has expired but is in a grace period. Please renew to avoid service interruption.',
            LicenseStatus::EXPIRED => function_exists('_') ? _('LICENSE_EXPIRED_MESSAGE') : 'License has expired. System is in read-only mode.',
            LicenseStatus::SUSPENDED => function_exists('_') ? _('LICENSE_SUSPENDED_MESSAGE') : 'License has been suspended. Please contact support.',
            LicenseStatus::INVALID => function_exists('_') ? _('LICENSE_INVALID_MESSAGE') : 'Invalid license. Please check your license key.',
            default => 'License status: ' . $status,
        };
    }

    /**
     * Get the database adapter (for testing/advanced use)
     *
     * @return DatabaseAdapterInterface
     */
    public function getDatabase(): DatabaseAdapterInterface
    {
        return $this->database;
    }

    /**
     * Get the session adapter (for testing/advanced use)
     *
     * @return SessionAdapterInterface
     */
    public function getSession(): SessionAdapterInterface
    {
        return $this->session;
    }
}
