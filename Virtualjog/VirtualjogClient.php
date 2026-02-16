<?php

declare(strict_types=1);

namespace Virtualjog;

use Virtualjog\Adapters\SessionStorage;
use Virtualjog\Contracts\StorageInterface;

/**
 * VirtualjogClient - Framework-agnostic Virtualjog API integration
 *
 * Pure PHP client for the Virtualjog SaaS service (Hungarian legaltech).
 * Provides authentication, legal document management with caching,
 * document type mapping, cookie consent module, and domain validation.
 *
 * @example
 * $client = new VirtualjogClient([
 *     'access_token' => 'your-token',
 *     'domain' => 'example.hu',
 *     'storage_adapter' => new MyDatabaseStorage($pdo),
 * ]);
 *
 * $result = $client->authorize();
 * if ($result->isSuccess()) {
 *     $documents = $client->getDocuments();
 *     echo $client->getDocumentEmbedHtmlByType('aszf');
 * }
 */
class VirtualjogClient
{
    /** @var string Virtualjog API base URL */
    private const API_BASE_URL = 'https://api.virtualjog.hu/api/v1/';

    // Storage keys
    private const STORAGE_ACCESS_TOKEN = 'virtualjog_access_token';
    private const STORAGE_CLIENT_DATA = 'virtualjog_client_data';
    private const STORAGE_COOKIE_SCRIPT = 'virtualjog_cookie_module_script';
    private const STORAGE_COOKIE_DOMAIN = 'virtualjog_cookie_module_domain';
    private const STORAGE_COOKIE_ENABLED = 'virtualjog_cookie_module_enabled';
    private const STORAGE_DOCUMENTS_CACHE = 'virtualjog_documents_cache';
    private const STORAGE_DOCUMENTS_CACHE_TIME = 'virtualjog_documents_cache_time';
    private const STORAGE_DOCUMENT_MAPPING = 'virtualjog_document_mapping';

    /** @var string Access token for API authentication */
    private string $accessToken;

    /** @var string Current site domain */
    private string $domain;

    /** @var int Document cache TTL in seconds */
    private int $cacheTtl;

    /** @var StorageInterface Storage adapter for persistence */
    private StorageInterface $storage;

    /** @var ApiClient HTTP client for API communication */
    private ApiClient $apiClient;

    /** @var CookieConsentManager Cookie consent handler */
    private CookieConsentManager $cookieManager;

    /**
     * Initialize the Virtualjog client
     *
     * @param array{
     *     access_token: string,
     *     domain?: string,
     *     cookie_lifetime?: int,
     *     cookie_path?: string,
     *     cookie_secure?: bool,
     *     cookie_samesite?: string,
     *     cache_ttl?: int,
     *     storage_adapter?: StorageInterface,
     *     api_timeout?: int,
     *     log_callback?: callable
     * } $config Configuration options:
     *   - access_token: (required) Virtualjog API access token
     *   - domain: Current site domain (default: $_SERVER['SERVER_NAME'])
     *   - cookie_lifetime: Cookie consent lifetime in seconds (default: 3600)
     *   - cookie_path: Cookie path (default: '/')
     *   - cookie_secure: Secure cookies only (default: true)
     *   - cookie_samesite: SameSite attribute (default: 'Lax')
     *   - cache_ttl: Document cache duration in seconds (default: 86400 = 24h)
     *   - storage_adapter: Custom StorageInterface implementation
     *   - api_timeout: cURL timeout in seconds (default: 15)
     *   - log_callback: fn(string $message, string $level)
     * @throws \InvalidArgumentException If access_token is missing or empty
     */
    public function __construct(array $config)
    {
        $this->registerAutoloader();

        if (empty($config['access_token'])) {
            throw new \InvalidArgumentException('The "access_token" configuration option is required.');
        }

        $this->accessToken = $config['access_token'];
        $this->domain = $config['domain'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        $this->cacheTtl = $config['cache_ttl'] ?? 86400;

        // Storage adapter
        if (isset($config['storage_adapter'])) {
            if (!$config['storage_adapter'] instanceof StorageInterface) {
                throw new \InvalidArgumentException(
                    'The "storage_adapter" must implement ' . StorageInterface::class
                );
            }
            $this->storage = $config['storage_adapter'];
        } else {
            $this->storage = new SessionStorage();
        }

        // API client
        $this->apiClient = new ApiClient(
            baseUrl: self::API_BASE_URL,
            timeout: $config['api_timeout'] ?? 15,
            logCallback: $config['log_callback'] ?? null
        );

        // Cookie consent manager
        $this->cookieManager = new CookieConsentManager(
            lifetime: $config['cookie_lifetime'] ?? 3600,
            path: $config['cookie_path'] ?? '/',
            secure: $config['cookie_secure'] ?? true,
            sameSite: $config['cookie_samesite'] ?? 'Lax'
        );

        // Persist access token in storage
        $this->storage->set(self::STORAGE_ACCESS_TOKEN, $this->accessToken);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * Authorize with the Virtualjog API using the configured access token
     *
     * Validates the token and stores client data (including packages) in storage.
     *
     * @return ApiResult Contains client data on success, error message on failure
     */
    public function authorize(): ApiResult
    {
        $result = $this->apiClient->post('wordpress-authorize', [
            'access_token' => $this->accessToken,
        ]);

        if ($result->isSuccess() && isset($result->data['client'])) {
            $this->storage->set(self::STORAGE_CLIENT_DATA, $result->data['client']);
        }

        return $result;
    }

    /**
     * Get stored client data from a previous successful authorization
     *
     * @return array<string, mixed>|null Client data array or null if not authorized
     */
    public function getClientData(): ?array
    {
        $data = $this->storage->get(self::STORAGE_CLIENT_DATA);

        return is_array($data) ? $data : null;
    }

    /**
     * Check if the client is currently authorized (has stored client data)
     *
     * @return bool
     */
    public function isAuthorized(): bool
    {
        return $this->getClientData() !== null;
    }

    /**
     * Clear all stored data (access token, client data, cache, cookie module state)
     *
     * @return void
     */
    public function logout(): void
    {
        $this->storage->remove(self::STORAGE_ACCESS_TOKEN);
        $this->storage->remove(self::STORAGE_CLIENT_DATA);
        $this->storage->remove(self::STORAGE_COOKIE_SCRIPT);
        $this->storage->remove(self::STORAGE_COOKIE_DOMAIN);
        $this->storage->remove(self::STORAGE_COOKIE_ENABLED);
        $this->clearDocumentCache();
    }

    // -------------------------------------------------------------------------
    // Documents
    // -------------------------------------------------------------------------

    /**
     * Fetch the list of legal documents associated with the account
     *
     * Results are cached in storage for the configured TTL (default: 24 hours).
     * Subsequent calls within the TTL return cached data without an API request.
     *
     * @param bool $forceRefresh Bypass cache and fetch from API (default: false)
     * @return ApiResult Contains 'documents' array on success
     */
    public function getDocuments(bool $forceRefresh = false): ApiResult
    {
        if (!$forceRefresh) {
            $cached = $this->getCachedDocuments();
            if ($cached !== null) {
                return ApiResult::success($cached);
            }
        }

        $result = $this->apiClient->post('wordpress-document-list', [
            'access_token' => $this->accessToken,
        ]);

        if ($result->isSuccess()) {
            $this->storage->set(self::STORAGE_DOCUMENTS_CACHE, $result->data);
            $this->storage->set(self::STORAGE_DOCUMENTS_CACHE_TIME, time());
        }

        return $result;
    }

    /**
     * Generate an iframe HTML embed string for a specific document
     *
     * @param string $embedUrl The document's embed URL (from getDocuments() response)
     * @param int    $height   Iframe height in pixels (default: 1000)
     * @param string $width    Iframe width as CSS value (default: '100%')
     * @return string HTML iframe element
     */
    public function getDocumentEmbedHtml(string $embedUrl, int $height = 1000, string $width = '100%'): string
    {
        $safeUrl = htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8');
        $safeWidth = htmlspecialchars($width, ENT_QUOTES, 'UTF-8');

        return '<iframe src="' . $safeUrl . '" width="' . $safeWidth
            . '" height="' . $height . 'px" style="border: none;"></iframe>';
    }

    /**
     * Store a document type-to-slug mapping
     *
     * Maps your website's document types (e.g., 'aszf', 'privacy') to
     * Virtualjog document slugs from the API.
     *
     * @param array<string, string> $mapping Type key => document slug
     * @return void
     */
    public function setDocumentMapping(array $mapping): void
    {
        $this->storage->set(self::STORAGE_DOCUMENT_MAPPING, $mapping);
    }

    /**
     * Retrieve the stored document type-to-slug mapping
     *
     * @return array<string, string> Type key => document slug
     */
    public function getDocumentMapping(): array
    {
        $mapping = $this->storage->get(self::STORAGE_DOCUMENT_MAPPING, []);

        return is_array($mapping) ? $mapping : [];
    }

    /**
     * Get a document by its mapped type key
     *
     * Looks up the slug from the stored mapping, then finds the matching
     * document in the (cached) document list.
     *
     * @param string $type Document type key (e.g., 'aszf', 'privacy')
     * @return array<string, mixed>|null Document data array or null if not found
     */
    public function getDocumentByType(string $type): ?array
    {
        $mapping = $this->getDocumentMapping();

        if (!isset($mapping[$type])) {
            return null;
        }

        $slug = $mapping[$type];
        $result = $this->getDocuments();

        if (!$result->isSuccess() || !isset($result->data['documents'])) {
            return null;
        }

        foreach ($result->data['documents'] as $document) {
            if (isset($document['slug']) && $document['slug'] === $slug) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Get embed HTML for a document by its mapped type key
     *
     * Convenience method that resolves: type → slug → document → embed HTML.
     *
     * @param string $type   Document type key (e.g., 'aszf', 'privacy')
     * @param int    $height Iframe height in pixels (default: 1000)
     * @param string $width  Iframe width as CSS value (default: '100%')
     * @return string|null HTML iframe element or null if document not found
     */
    public function getDocumentEmbedHtmlByType(string $type, int $height = 1000, string $width = '100%'): ?string
    {
        $document = $this->getDocumentByType($type);

        if ($document === null || !isset($document['embedUrl'])) {
            return null;
        }

        return $this->getDocumentEmbedHtml($document['embedUrl'], $height, $width);
    }

    /**
     * Clear the document list cache
     *
     * @return void
     */
    public function clearDocumentCache(): void
    {
        $this->storage->remove(self::STORAGE_DOCUMENTS_CACHE);
        $this->storage->remove(self::STORAGE_DOCUMENTS_CACHE_TIME);
    }

    // -------------------------------------------------------------------------
    // Domain Validation
    // -------------------------------------------------------------------------

    /**
     * Fetch the list of valid (allowed) domains for this account
     *
     * @return ApiResult Contains 'domains' string (comma-separated) on success
     */
    public function getValidDomains(): ApiResult
    {
        return $this->apiClient->post('wordpress-valid-domains', [
            'access_token' => $this->accessToken,
        ]);
    }

    /**
     * Check if the current domain (or a given domain) is in the allowed domains list
     *
     * @param string|null $domain Domain to check, defaults to configured domain
     * @return bool
     */
    public function isDomainValid(?string $domain = null): bool
    {
        $checkDomain = $domain ?? $this->domain;
        $result = $this->getValidDomains();

        if (!$result->isSuccess() || !isset($result->data['domains'])) {
            return false;
        }

        $validDomains = array_map('trim', explode(',', $result->data['domains']));

        return in_array($checkDomain, $validDomains, true);
    }

    // -------------------------------------------------------------------------
    // Cookie Module
    // -------------------------------------------------------------------------

    /**
     * Enable the cookie consent module
     *
     * Fetches the cookie script from the API for the configured domain
     * and stores it for injection into page HTML.
     *
     * @return ApiResult Contains 'script' string on success
     */
    public function enableCookieModule(): ApiResult
    {
        $result = $this->apiClient->post('wordpress-cookie-script', [
            'access_token' => $this->accessToken,
            'domain' => $this->domain,
        ]);

        if ($result->isSuccess() && isset($result->data['script'])) {
            $this->storage->set(self::STORAGE_COOKIE_SCRIPT, $result->data['script']);
            $this->storage->set(self::STORAGE_COOKIE_DOMAIN, $this->domain);
            $this->storage->set(self::STORAGE_COOKIE_ENABLED, true);
        }

        return $result;
    }

    /**
     * Disable the cookie consent module
     *
     * Clears the stored cookie script and marks the module as disabled.
     *
     * @return void
     */
    public function disableCookieModule(): void
    {
        $this->storage->remove(self::STORAGE_COOKIE_SCRIPT);
        $this->storage->remove(self::STORAGE_COOKIE_DOMAIN);
        $this->storage->set(self::STORAGE_COOKIE_ENABLED, false);
    }

    /**
     * Check whether the cookie consent module is currently enabled
     *
     * @return bool
     */
    public function isCookieModuleEnabled(): bool
    {
        return (bool) $this->storage->get(self::STORAGE_COOKIE_ENABLED, false);
    }

    /**
     * Get the cookie consent script HTML for injection into the page head
     *
     * Returns the stored script tag HTML, or null if the cookie module
     * is not enabled or the script has not been fetched yet.
     *
     * @return string|null HTML script tag or null
     */
    public function getCookieScriptHtml(): ?string
    {
        if (!$this->isCookieModuleEnabled()) {
            return null;
        }

        $script = $this->storage->get(self::STORAGE_COOKIE_SCRIPT);

        return is_string($script) && $script !== '' ? $script : null;
    }

    // -------------------------------------------------------------------------
    // Cookie Consent
    // -------------------------------------------------------------------------

    /**
     * Check if a specific provider category has user consent
     *
     * @param string $category One of: 'stat', 'marketing', 'other'
     * @return bool
     * @throws \InvalidArgumentException If category is unknown
     */
    public function hasConsent(string $category): bool
    {
        return $this->cookieManager->hasConsent($category);
    }

    /**
     * Get the CookieConsentManager instance for direct cookie consent operations
     *
     * @return CookieConsentManager
     */
    public function getCookieConsentManager(): CookieConsentManager
    {
        return $this->cookieManager;
    }

    // -------------------------------------------------------------------------
    // Package Management
    // -------------------------------------------------------------------------

    /**
     * Check if a specific package is active in the client's subscription
     *
     * @param string $packageSlug Package slug (e.g., 'cookie-panel')
     * @return bool True if the package exists, is active, and subscription is not expired
     */
    public function hasActivePackage(string $packageSlug): bool
    {
        $clientData = $this->getClientData();

        if ($clientData === null || !isset($clientData['packages'])) {
            return false;
        }

        foreach ($clientData['packages'] as $package) {
            if (
                isset($package['slug']) && $package['slug'] === $packageSlug
                && isset($package['active']) && $package['active'] === true
            ) {
                // Check subscription end date if present
                if (isset($package['subscriptionEndDate']) && $package['subscriptionEndDate'] !== '') {
                    $endDate = strtotime($package['subscriptionEndDate']);
                    if ($endDate !== false && $endDate < time()) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Get the storage adapter
     *
     * @return StorageInterface
     */
    public function getStorage(): StorageInterface
    {
        return $this->storage;
    }

    /**
     * Get the configured domain
     *
     * @return string
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Get cached documents if the cache is still valid
     *
     * @return array<string, mixed>|null Cached data or null if expired/missing
     */
    private function getCachedDocuments(): ?array
    {
        if (!$this->storage->has(self::STORAGE_DOCUMENTS_CACHE)) {
            return null;
        }

        $cacheTime = $this->storage->get(self::STORAGE_DOCUMENTS_CACHE_TIME);

        if (!is_int($cacheTime) || (time() - $cacheTime) >= $this->cacheTtl) {
            return null;
        }

        $cached = $this->storage->get(self::STORAGE_DOCUMENTS_CACHE);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Register PSR-4 autoloader for Virtualjog namespace
     *
     * @return void
     */
    private function registerAutoloader(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'Virtualjog\\';
            $baseDir = __DIR__ . '/';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', '/', $relativeClass) . '.php';

            // Classes in Adapters\ namespace live at root level: Adapters/ClassName.php
            if (str_starts_with($relativeClass, 'Adapters\\')) {
                $file = $baseDir . $relativePath;
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }

            // All other classes live in src/: src/ClassName.php, src/Contracts/ClassName.php
            $srcFile = $baseDir . 'src/' . $relativePath;
            if (file_exists($srcFile)) {
                require_once $srcFile;
                return;
            }
        });
    }
}
