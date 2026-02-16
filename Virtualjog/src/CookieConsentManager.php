<?php

declare(strict_types=1);

namespace Virtualjog;

/**
 * Cookie consent manager for Virtualjog cookie module
 *
 * Manages consent cookies that control whether statistical, marketing,
 * and other tracking providers are allowed. Works with the Virtualjog
 * cookie panel JavaScript to read/write consent state via PHP cookies.
 *
 * @example
 * $manager = new CookieConsentManager(lifetime: 3600);
 * if ($manager->hasConsent('stat')) {
 *     // Load Google Analytics
 * }
 */
class CookieConsentManager
{
    /** @var string Cookie name for statistical providers consent */
    public const COOKIE_STAT = 'vjog_allow_stat_providers';

    /** @var string Cookie name for marketing providers consent */
    public const COOKIE_MARKETING = 'vjog_allow_marketing_providers';

    /** @var string Cookie name for other providers consent */
    public const COOKIE_OTHER = 'vjog_allow_other_providers';

    /** @var array<string, string> Map of category names to cookie names */
    private const CATEGORY_MAP = [
        'stat' => self::COOKIE_STAT,
        'marketing' => self::COOKIE_MARKETING,
        'other' => self::COOKIE_OTHER,
    ];

    /** @var array<string, list<string>> Known script handles mapped to consent categories */
    private const PROVIDER_HANDLES = [
        'stat' => [
            'googlesitekit',
            'google_gtagjs',
        ],
        'marketing' => [
            'facebook',
        ],
        'other' => [
            'doubleclick',
            'adsbygoogle',
        ],
    ];

    /** @var int Cookie lifetime in seconds */
    private int $lifetime;

    /** @var string Cookie path */
    private string $path;

    /** @var bool Secure flag */
    private bool $secure;

    /** @var string SameSite attribute */
    private string $sameSite;

    /**
     * Initialize the cookie consent manager
     *
     * @param int    $lifetime Cookie lifetime in seconds (default: 3600)
     * @param string $path     Cookie path (default: '/')
     * @param bool   $secure   Secure flag (default: true)
     * @param string $sameSite SameSite attribute (default: 'Lax')
     */
    public function __construct(
        int $lifetime = 3600,
        string $path = '/',
        bool $secure = true,
        string $sameSite = 'Lax'
    ) {
        $this->lifetime = $lifetime;
        $this->path = $path;
        $this->secure = $secure;
        $this->sameSite = $sameSite;
    }

    /**
     * Check if user has given consent for a specific provider category
     *
     * @param string $category One of: 'stat', 'marketing', 'other'
     * @return bool True if consent cookie exists and is truthy
     * @throws \InvalidArgumentException If category is unknown
     */
    public function hasConsent(string $category): bool
    {
        $cookieName = $this->resolveCookieName($category);

        return isset($_COOKIE[$cookieName]) && (bool) $_COOKIE[$cookieName];
    }

    /**
     * Set consent for a specific provider category
     *
     * @param string $category One of: 'stat', 'marketing', 'other'
     * @param bool   $allowed  Whether to allow or deny the category
     * @return void
     * @throws \InvalidArgumentException If category is unknown
     */
    public function setConsent(string $category, bool $allowed): void
    {
        $cookieName = $this->resolveCookieName($category);

        setcookie($cookieName, $allowed ? '1' : '0', [
            'expires' => time() + $this->lifetime,
            'path' => $this->path,
            'secure' => $this->secure,
            'httponly' => false,
            'samesite' => $this->sameSite,
        ]);

        // Update superglobal for same-request reads
        $_COOKIE[$cookieName] = $allowed ? '1' : '0';
    }

    /**
     * Process consent from a JSON request body
     *
     * Reads JSON from php://input with keys:
     * - allowStatProviders: bool
     * - allowMarketingProviders: bool
     * - allowOtherProviders: bool
     *
     * Sets the corresponding cookies and returns the processed consent state.
     *
     * @return array<string, bool> Map of category => consent value that was set
     */
    public function processConsentRequest(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body !== false ? $body : '', true);

        if (!is_array($data)) {
            return [];
        }

        $requestMap = [
            'allowStatProviders' => 'stat',
            'allowMarketingProviders' => 'marketing',
            'allowOtherProviders' => 'other',
        ];

        $result = [];

        foreach ($requestMap as $jsonKey => $category) {
            if (isset($data[$jsonKey]) && is_bool($data[$jsonKey])) {
                $this->setConsent($category, $data[$jsonKey]);
                $result[$category] = $data[$jsonKey];
            }
        }

        return $result;
    }

    /**
     * Clear all consent cookies
     *
     * @return void
     */
    public function clearAllConsent(): void
    {
        foreach (self::CATEGORY_MAP as $category => $cookieName) {
            setcookie($cookieName, '', [
                'expires' => time() - 3600,
                'path' => $this->path,
                'secure' => $this->secure,
                'httponly' => false,
                'samesite' => $this->sameSite,
            ]);

            unset($_COOKIE[$cookieName]);
        }
    }

    /**
     * Get all current consent states
     *
     * @return array<string, bool> Map of category => consent status
     */
    public function getAllConsent(): array
    {
        $result = [];

        foreach (self::CATEGORY_MAP as $category => $cookieName) {
            $result[$category] = isset($_COOKIE[$cookieName]) && (bool) $_COOKIE[$cookieName];
        }

        return $result;
    }

    /**
     * Check if a tracking script should be allowed based on current consent
     *
     * Given a script handle/identifier, checks it against known provider patterns
     * and returns whether the script should be allowed based on current consent.
     * Scripts that don't match any known provider are always allowed.
     *
     * @param string $scriptHandle Script identifier to check (e.g., 'googlesitekit', 'facebook')
     * @return bool True if the script is allowed (consent given or not a tracked provider)
     */
    public function isScriptAllowed(string $scriptHandle): bool
    {
        $scriptHandleLower = strtolower($scriptHandle);

        foreach (self::PROVIDER_HANDLES as $category => $handles) {
            foreach ($handles as $handle) {
                if (str_contains($scriptHandleLower, $handle)) {
                    return $this->hasConsent($category);
                }
            }
        }

        // Unknown provider — allow by default
        return true;
    }

    /**
     * Resolve a category name to its cookie name
     *
     * @param string $category One of: 'stat', 'marketing', 'other'
     * @return string Cookie name
     * @throws \InvalidArgumentException If category is unknown
     */
    private function resolveCookieName(string $category): string
    {
        if (!isset(self::CATEGORY_MAP[$category])) {
            throw new \InvalidArgumentException(
                "Unknown consent category '{$category}'. Valid categories: "
                . implode(', ', array_keys(self::CATEGORY_MAP))
            );
        }

        return self::CATEGORY_MAP[$category];
    }
}
