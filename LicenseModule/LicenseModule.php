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
 * if ($license->hasFeature('reports')) {
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
    private AdminPageRenderer $adminRenderer;
    private SessionAdapterInterface $session;
    private DatabaseAdapterInterface $database;
    private array $config;
    private string $locale;
    private ?TranslatorInterface $translator = null;

    /** @var callable|null Optional logging callback: fn(string $message, string $level) */
    private $logCallback = null;

    /**
     * Initialize the license module
     *
     * @param array $config Configuration array:
     *   - get_pdo: callable():PDO - Required. Returns PDO connection
     *   - server_url: string - License server URL (optional, has default)
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
        if (isset($config['log_callback']) && is_callable($config['log_callback'])) {
            $this->logCallback = $config['log_callback'];
        }
        $this->initializeAdapters($config);
        $this->initializeValidator($config);
        $this->initializeFeatureGate();
        $this->initializeAdminRenderer();
    }

    /**
     * Log a message via the configured log_callback, if any. Never throws —
     * logging must not itself be a source of failure.
     *
     * @param string $message
     * @param string $level e.g. 'WARNING', 'ERROR'
     */
    private function log(string $message, string $level): void
    {
        if ($this->logCallback === null) {
            return;
        }

        try {
            ($this->logCallback)($message, $level);
        } catch (\Throwable) {
            // Swallow — a broken host logger must not break license gating.
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
     *
     * @throws \InvalidArgumentException When "http_client" is set but doesn't implement HttpClientInterface
     */
    private function initializeValidator(array $config): void
    {
        if (isset($config['http_client'])) {
            if (!$config['http_client'] instanceof HttpClientInterface) {
                throw new \InvalidArgumentException(
                    'LicenseModule "http_client" must implement ' . HttpClientInterface::class
                );
            }
            $httpClient = $config['http_client'];
        } else {
            $httpClient = new CurlHttpClient();
        }

        $serverUrl = $config['server_url'] ?? self::DEFAULT_SERVER_URL;

        $this->validator = new LicenseValidator(
            $this->database,
            $httpClient,
            $serverUrl,
            $this->logCallback
        );
    }

    /**
     * Initialize the feature gate
     */
    private function initializeFeatureGate(): void
    {
        $this->featureGate = new FeatureGate(fn() => $this->getParsedFeatures());
    }

    /**
     * Initialize the admin page renderer. Must run after $this->locale and
     * $this->translator are set (both assigned earlier in the constructor).
     */
    private function initializeAdminRenderer(): void
    {
        $this->adminRenderer = new AdminPageRenderer(
            __DIR__ . '/views/admin/license.php',
            __DIR__ . '/locale',
            $this->locale,
            $this->translator
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
     * @return array Always contains 'success' (bool), 'status' (string), 'message' (string).
     *               Conditionally present: 'data' (raw server response) only when the
     *               server was actually reached (both the valid and invalid-but-reachable
     *               branches) — absent on a throttled (429) result and on all offline
     *               results; 'throttled' => true only on a 429 result; 'offline' => true
     *               only on the cached-fallback results. Do not assume 'data' is always set.
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
     * Get current tier information
     *
     * @return array|null Tier object {slug, name, level, description}; null for a
     *                     legacy license, or for a valid license with no tier assigned
     *                     (addon-only mode)
     */
    public function getTier(): ?array
    {
        return $this->featureGate->getTier();
    }

    /**
     * Get current tier level
     *
     * @return int Tier level (0 if legacy/invalid or no tier assigned)
     */
    public function getTierLevel(): int
    {
        return $this->featureGate->getTierLevel();
    }

    /**
     * Check whether the current tier matches an exact slug
     *
     * @param string $slug Tier slug to match
     * @return bool
     */
    public function hasTier(string $slug): bool
    {
        return $this->featureGate->hasTier($slug);
    }

    /**
     * Check whether the current tier level meets a minimum threshold.
     * Pure predicate — does not enforce or block; the host acts on the result.
     *
     * @param int $minLevel Minimum required tier level
     * @return bool
     */
    public function requireTierLevel(int $minLevel): bool
    {
        return $this->featureGate->requireTierLevel($minLevel);
    }

    /**
     * Check if an addon is enabled
     *
     * Checks the license's addon list (feature_key match) — use for gating on a
     * specific purchasable/marketed add-on. For a general gating check that also
     * covers tier-granted features with no matching addon entry, use hasFeature().
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
     * Get the full addon catalog for the license's package — every addon
     * available in that package, not just the ones currently activated.
     *
     * @return array<int, array{feature_key: string, name: string, description: string|null,
     *                          price: mixed, price_currency: string|null, billing_period: string|null,
     *                          requires_tier_level: int|null, status: string|null, sort_order: mixed,
     *                          activated: bool, tier_eligible: bool}>
     */
    public function getAddonCatalog(): array
    {
        return $this->featureGate->getAddonCatalog();
    }

    /**
     * Get the flat list of enabled feature keys resolved by the license server.
     * The authoritative enabled-feature set: covers both tier-granted and
     * addon-granted feature keys. General-purpose gating should check this
     * via hasFeature() rather than reconstructing tier/addon logic host-side.
     *
     * @return string[]
     */
    public function getFeatureKeys(): array
    {
        return $this->featureGate->getFeatureKeys();
    }

    /**
     * Check whether a specific feature key is enabled.
     * The general-purpose gating check — see getFeatureKeys().
     *
     * @param string $key Feature key
     * @return bool
     */
    public function hasFeature(string $key): bool
    {
        return $this->featureGate->hasFeature($key);
    }

    /**
     * Get the license's package information, if any.
     *
     * @return array{id: int, name: string|null, slug: string|null}|null
     */
    public function getPackage(): ?array
    {
        return $this->featureGate->getPackage();
    }

    /**
     * Evaluate a single gating requirement against the current license.
     * Deny-by-default dispatch; see FeatureGate::allows() for the full contract
     * (recognized keys: addon, tier, min_tier_level, feature, any_of, all_of).
     *
     * @param array $requirement Single-key requirement, or an any_of/all_of composition
     * @return bool
     */
    public function allows(array $requirement): bool
    {
        return $this->featureGate->allows($requirement);
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

        $expiryTime = self::parseTimestamp($licenseInfo['expires_at']);

        if ($expiryTime === null) {
            return null;
        }

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

        $graceExpiryTime = self::parseTimestamp($graceExpiresAt);

        if ($graceExpiryTime === null) {
            return null;
        }

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
     *   @type array                $module_names    feature_key→display-name map for the included-features list
     *   @type string               $date_format     PHP date() format (default 'Y-m-d')
     *   @type string               $datetime_format PHP date() format (default 'Y-m-d H:i:s')
     *   @type int                  $history_limit   Max history rows (default 20)
     * }
     * @return string Rendered HTML fragment, or empty string if the view file is not yet present
     *
     * Gathers license/gating data and delegates locale/translator resolution and
     * the actual view rendering to AdminPageRenderer. Injects into the view:
     * $license, $status, $tier, $addons, $featureKeys, $isLegacy (true only for a
     * genuine legacy/no-tier-data license — under the deny-by-default gating
     * policy this means every gating call denies, not that "all features are
     * enabled"), $history, $daysRemaining, $graceDaysRemaining, $options, $t.
     */
    public function renderAdminPage(array $options = []): string
    {
        if (!$this->adminRenderer->viewExists()) {
            return '';
        }

        $license = $this->getLatestLicenseInfo();

        $viewData = [
            'license' => $license,
            // Derived from the raw (unfiltered) row so suspended/invalid licenses
            // display correctly. Applies the same expiry checks as getCurrentStatus().
            'status' => $this->deriveDisplayStatus($license),
            'tier' => $this->getTier(),
            'addons' => $this->getAddons(),
            'featureKeys' => $this->getFeatureKeys(),
            // True legacy (no tier/addon/feature data at all — the historical ['all']
            // sentinel) vs. a valid tier-less/addon-only license with nothing granted
            // are indistinguishable from tier/addons alone (both null/empty in either
            // case), so this is derived from FeatureGate's own source of truth rather
            // than guessed here. Reads through the memoized cache already warmed by
            // getTier()/getAddons()/getFeatureKeys() above — no extra database read.
            'isLegacy' => $this->featureGate->isLegacy(),
            'history' => $this->getValidationHistory((int) ($options['history_limit'] ?? 20)),
            'daysRemaining' => $this->getDaysUntilExpiration(),
            'graceDaysRemaining' => $this->getDaysUntilGraceExpiration(),
        ];

        return $this->adminRenderer->render($viewData, $options);
    }

    /**
     * Derive the license status to display on the admin page from a raw
     * (unfiltered) license_info row, applying the same grace/expiry checks as
     * LicenseValidator::getCurrentStatus() — but on the unfiltered row, so
     * suspended/invalid licenses display correctly instead of disappearing.
     *
     * @param array|null $license Raw row from getLatestLicenseInfo()
     * @return string One of the LicenseStatus constants
     */
    private function deriveDisplayStatus(?array $license): string
    {
        if ($license === null) {
            return LicenseStatus::INVALID;
        }

        $rawStatus = $license['status'] ?? LicenseStatus::INVALID;

        // Blocked statuses are terminal — never let date-based reclassification
        // below downgrade a suspended/invalid license into merely "expired".
        // Identical logic to LicenseValidator::getCurrentStatus().
        if (in_array($rawStatus, [LicenseStatus::SUSPENDED, LicenseStatus::INVALID], true)) {
            return $rawStatus;
        }

        if ($rawStatus === LicenseStatus::GRACE) {
            $graceExpiry = self::parseTimestamp($license['grace_expires_at'] ?? null);

            if ($graceExpiry !== null && $graceExpiry < time()) {
                return LicenseStatus::EXPIRED;
            }

            return LicenseStatus::GRACE;
        }

        // An unparseable expiry date means "don't reclassify" rather than "treat as past".
        $expiry = self::parseTimestamp($license['expires_at'] ?? null);

        if ($expiry !== null && $expiry < time()) {
            return LicenseStatus::EXPIRED;
        }

        return $rawStatus;
    }

    // =========================================================================
    // Internal Helpers
    // =========================================================================

    /**
     * Get parsed features from license info
     *
     * Orchestrates three independent concerns, kept in their own methods so a
     * future validation rule costs one small method + one line — not a growing
     * pile in a single method:
     *   1. fetch (with fail-safe error handling — see below)
     *   2. shape recognition (the legacy/malformed gate — {@see isRecognizedFeatureFormat()})
     *   3. field diagnostics on an already-recognized payload — {@see validateFeatureFields()})
     *
     * @return array{tier: array|null, addons: array, feature_keys: array, package: array|null, addon_catalog: array}|null
     *         Structured features, or null for a legacy/unrestricted license
     */
    private function getParsedFeatures(): ?array
    {
        try {
            $licenseInfo = $this->validator->getLicenseInfo();
        } catch (\Throwable $e) {
            $this->log(
                'LicenseModule: failed to load license info for feature gating (' . $e->getMessage() . '); denying by default',
                'ERROR'
            );
            return null;
        }

        if ($licenseInfo === null || empty($licenseInfo['features'])) {
            return null;
        }

        $features = json_decode($licenseInfo['features'], true);
        // Captured immediately after decode, with nothing else touching JSON in
        // between — json_last_error() is process-global, so this ordering must
        // never be allowed to drift as the method evolves. Passed as a parameter
        // rather than re-read inside isRecognizedFeatureFormat() for exactly
        // that reason.
        $jsonError = json_last_error();

        if (!$this->isRecognizedFeatureFormat($features, $jsonError)) {
            return null; // logging (if any) already happened inside the gate
        }

        $this->validateFeatureFields($features); // non-fatal diagnostics only

        return [
            'tier' => $features['tier'] ?? null,
            'addons' => $features['addons'] ?? [],
            'feature_keys' => $features['feature_keys'] ?? [],
            'package' => $features['package'] ?? null,
            'addon_catalog' => $features['addon_catalog'] ?? [],
        ];
    }

    /**
     * Shape-recognition gate: distinguishes the legacy `['all']` sentinel (no
     * 'tier' key at all) from a structured, non-legacy license whose tier
     * happens to be null (addon-only mode — see FeatureGate) from genuinely
     * malformed/corrupt data.
     *
     * Uses array_key_exists rather than isset() because isset() is false for an
     * explicit null value, which would otherwise misclassify a package-only/
     * tier-less license as legacy and silently drop its addons/feature_keys/
     * package.
     *
     * Only the exact `['all']` sentinel is an expected, silent legacy case —
     * anything else that fails to parse into the structured shape is a real
     * data anomaly (corrupted/truncated JSON, unexpected server format) and is
     * logged so it doesn't look identical to an intentional legacy license when
     * diagnosing why gating denies everything.
     *
     * @param mixed $features Decoded JSON payload (or null/scalar on decode failure)
     * @param int $jsonError json_last_error() captured right after decode by the caller
     * @return bool True if $features is the structured {tier, addons, ...} shape
     */
    private function isRecognizedFeatureFormat(mixed $features, int $jsonError): bool
    {
        if (is_array($features) && array_key_exists('tier', $features)) {
            return true;
        }

        if ($features !== ['all']) {
            $this->log(
                'LicenseModule: license_info.features did not match a recognized format'
                    . ($jsonError !== JSON_ERROR_NONE ? ' (JSON error: ' . json_last_error_msg() . ')' : '')
                    . '; treating as legacy/deny-by-default',
                'WARNING'
            );
        }

        return false;
    }

    /**
     * Field-diagnostics dispatcher for an already-recognized structured feature
     * payload. Never changes the caller's return value — logs only. A future
     * rule (e.g. validating `addons[].feature_key` shape, `package.id` type)
     * costs one more line here, nothing else in getParsedFeatures() changes.
     *
     * @param array $features Structured feature payload (already passed the shape gate)
     */
    private function validateFeatureFields(array $features): void
    {
        $this->warnOnNonNumericTierLevel($features['tier'] ?? null);
    }

    /**
     * Log a warning if the license server sent a non-numeric tier level.
     * FeatureGate::getTier() safely defaults such a level to 0 regardless —
     * this is diagnostics only, not a correctness guard.
     *
     * @param mixed $tier Tier payload (array|null) as received from the server
     */
    private function warnOnNonNumericTierLevel(mixed $tier): void
    {
        if (is_array($tier) && isset($tier['level']) && !is_numeric($tier['level'])) {
            $this->log(
                'LicenseModule: license server sent a non-numeric tier level ('
                    . var_export($tier['level'], true) . '); tier level will default to 0',
                'WARNING'
            );
        }
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
     * Note: no GRACE arm — checkMiddlewareJson() (this method's only caller)
     * already returns null for any isActive() status, which includes GRACE, so
     * this method is never reached with status GRACE. (LICENSE_GRACE_MESSAGE is
     * still used directly by views/grace.php.)
     *
     * @param string $status License status
     * @return string Human-readable message
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            LicenseStatus::EXPIRED => $this->translateWithFallback('LICENSE_EXPIRED_MESSAGE', 'License has expired. System is in read-only mode.'),
            LicenseStatus::SUSPENDED => $this->translateWithFallback('LICENSE_SUSPENDED_MESSAGE', 'License has been suspended. Please contact support.'),
            LicenseStatus::INVALID => $this->translateWithFallback('LICENSE_INVALID_MESSAGE', 'Invalid license. Please check your license key.'),
            default => 'License status: ' . $status,
        };
    }

    /**
     * Translate a gettext key with a safe fallback.
     *
     * Doesn't trust function_exists('_') alone — gettext returns the key itself
     * unchanged when the extension is loaded but no .mo catalog is bound for it,
     * which would otherwise leak raw keys like "LICENSE_EXPIRED_MESSAGE" to
     * end users instead of a readable fallback.
     *
     * @param string $key Gettext translation key
     * @param string $fallback English fallback text
     * @return string
     */
    private function translateWithFallback(string $key, string $fallback): string
    {
        if (!function_exists('_')) {
            return $fallback;
        }

        $translated = _($key);

        return ($translated === $key) ? $fallback : $translated;
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
