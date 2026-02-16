# Virtualjog

Framework-agnostic PHP client for the Virtualjog SaaS service (Hungarian legaltech).

## Features

- **Authentication**: Validate access token and retrieve client/subscription data
- **Legal Documents**: Fetch document list with 24-hour persistent caching
- **Document Type Mapping**: Map your site's document types (e.g., 'aszf', 'privacy') to Virtualjog document slugs
- **Document Embedding**: Generate iframe HTML for embedding legal documents into pages
- **Cookie Consent Module**: Fetch and inject cookie consent script, manage consent cookies
- **Domain Validation**: Verify current domain against allowed domains list
- **Package Management**: Check active subscriptions and package availability
- Framework-agnostic with custom storage adapter support (`StorageInterface`)
- cURL-based API communication with configurable timeout and logging

## Requirements

- PHP 8.3+
- cURL extension
- Virtualjog account with access token ([virtualjog.hu](https://virtualjog.hu))

## Installation

1. Copy the `Virtualjog` folder to your project's `lib/` directory
2. Include or autoload `VirtualjogClient.php`

## Quick Start

```php
<?php

require_once 'lib/Virtualjog/VirtualjogClient.php';

use Virtualjog\VirtualjogClient;

// Initialize with your access token
$client = new VirtualjogClient([
    'access_token' => 'your-virtualjog-token',
    'domain' => 'example.hu',
    'storage_adapter' => new MyDatabaseStorage($pdo), // recommended for production
]);

// Authorize (validates token, stores client data)
$result = $client->authorize();
if (!$result->isSuccess()) {
    die('Authorization failed: ' . $result->getErrorMessage());
}

// Fetch and display legal documents
$docs = $client->getDocuments();
if ($docs->isSuccess()) {
    foreach ($docs->getData()['documents'] as $doc) {
        echo $doc['name'] . ' (v' . $doc['lastVersion'] . ")\n";
    }
}

// Embed a document using type mapping
$client->setDocumentMapping([
    'aszf' => 'altalanos-szerzodesi-feltetelek',
    'privacy' => 'adatvedelmi-tajekoztato',
]);

echo $client->getDocumentEmbedHtmlByType('aszf');
```

## Configuration

```php
$client = new VirtualjogClient([
    // Required
    'access_token' => 'your-token',

    // Optional
    'domain'          => 'example.hu',        // Default: $_SERVER['SERVER_NAME']
    'cache_ttl'       => 86400,               // Document cache TTL in seconds (default: 24h)
    'cookie_lifetime' => 3600,                // Consent cookie lifetime in seconds
    'cookie_path'     => '/',                 // Consent cookie path
    'cookie_secure'   => true,                // Secure-only cookies
    'cookie_samesite' => 'Lax',              // SameSite attribute
    'api_timeout'     => 15,                  // cURL timeout in seconds
    'storage_adapter' => $myStorage,          // StorageInterface implementation
    'log_callback'    => function (string $msg, string $level) {
        error_log("[Virtualjog][{$level}] {$msg}");
    },
]);
```

## API Reference

### Authentication

| Method                  | Returns       | Description                                          |
|-------------------------|---------------|------------------------------------------------------|
| `authorize()`           | `ApiResult`   | Validate access token and store client data          |
| `getClientData()`       | `?array`      | Get stored client data from previous authorization   |
| `isAuthorized()`        | `bool`        | Check if client data exists in storage               |
| `logout()`              | `void`        | Clear all stored data (token, client, cache, cookie) |

### Documents

| Method                                                  | Returns    | Description                                        |
|---------------------------------------------------------|------------|----------------------------------------------------|
| `getDocuments(bool $forceRefresh = false)`               | `ApiResult` | Fetch documents (cached for 24h by default)        |
| `getDocumentEmbedHtml(string $embedUrl, ...)`            | `string`   | Generate iframe HTML for a document embed URL      |
| `setDocumentMapping(array $mapping)`                     | `void`     | Store type-to-slug mapping                         |
| `getDocumentMapping()`                                   | `array`    | Retrieve stored mapping                            |
| `getDocumentByType(string $type)`                        | `?array`   | Find document by mapped type key                   |
| `getDocumentEmbedHtmlByType(string $type, ...)`          | `?string`  | Get embed HTML by mapped type (type -> slug -> embed) |
| `clearDocumentCache()`                                   | `void`     | Manually clear document cache                      |

### Domain Validation

| Method                                 | Returns    | Description                                |
|----------------------------------------|------------|--------------------------------------------|
| `getValidDomains()`                    | `ApiResult` | Fetch allowed domains from API            |
| `isDomainValid(?string $domain = null)` | `bool`     | Check if domain is in the allowed list    |

### Cookie Module

| Method                       | Returns    | Description                                       |
|------------------------------|------------|---------------------------------------------------|
| `enableCookieModule()`       | `ApiResult` | Fetch cookie script from API and enable module   |
| `disableCookieModule()`      | `void`     | Clear cookie script and disable module            |
| `isCookieModuleEnabled()`    | `bool`     | Check if module is enabled                        |
| `getCookieScriptHtml()`      | `?string`  | Get script HTML for page `<head>` injection       |

### Cookie Consent

| Method                              | Returns                | Description                                |
|-------------------------------------|------------------------|--------------------------------------------|
| `hasConsent(string $category)`      | `bool`                 | Check consent for 'stat', 'marketing', 'other' |
| `getCookieConsentManager()`         | `CookieConsentManager` | Get the consent manager for direct access  |

### CookieConsentManager Methods

| Method                                          | Returns            | Description                                           |
|-------------------------------------------------|--------------------|-------------------------------------------------------|
| `hasConsent(string $category)`                  | `bool`             | Check if consent cookie is set and truthy             |
| `setConsent(string $category, bool $allowed)`   | `void`             | Set a consent cookie                                  |
| `processConsentRequest()`                       | `array<string,bool>` | Process JSON consent from `php://input`              |
| `clearAllConsent()`                             | `void`             | Remove all consent cookies                            |
| `getAllConsent()`                                | `array<string,bool>` | Get all current consent states                       |
| `isScriptAllowed(string $scriptHandle)`         | `bool`             | Check if a script handle is allowed by consent        |

### Package Management

| Method                                    | Returns | Description                                         |
|-------------------------------------------|---------|-----------------------------------------------------|
| `hasActivePackage(string $packageSlug)`   | `bool`  | Check if a package is active and not expired        |

### ApiResult Properties

| Property       | Type      | Description                |
|----------------|-----------|----------------------------|
| `success`      | `bool`    | Whether the call succeeded |
| `data`         | `?array`  | Response data from API     |
| `errorMessage` | `?string` | Error description          |
| `httpCode`     | `?int`    | HTTP status code           |

## Cookie Consent Integration

### 1. Enable the cookie module (admin/settings page)

```php
if ($client->hasActivePackage('cookie-panel') && $client->isDomainValid()) {
    $result = $client->enableCookieModule();
}
```

### 2. Inject cookie script in page `<head>`

```php
<head>
    <?php
    $script = $client->getCookieScriptHtml();
    if ($script !== null) {
        echo $script;
    }
    ?>
</head>
```

### 3. Create a consent endpoint

The Virtualjog cookie panel JS sends consent choices to your server. Create an endpoint that calls:

```php
// POST /your-app/cookie-consent-handler
$consents = $client->getCookieConsentManager()->processConsentRequest();
// Sets vjog_allow_stat_providers, vjog_allow_marketing_providers, vjog_allow_other_providers cookies
```

### 4. Conditionally load tracking scripts

```php
<?php if ($client->hasConsent('stat')): ?>
    <script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXX"></script>
<?php endif; ?>

<?php if ($client->hasConsent('marketing')): ?>
    <!-- Facebook Pixel -->
<?php endif; ?>
```

## Document Type Mapping

Map your website's document types to Virtualjog document slugs:

```php
// Save mapping (typically on your settings page)
$client->setDocumentMapping([
    'aszf'         => 'altalanos-szerzodesi-feltetelek',
    'privacy'      => 'adatvedelmi-tajekoztato',
    'cookie_policy' => 'suti-szabalyzat',
]);

// Use in templates
$embedHtml = $client->getDocumentEmbedHtmlByType('aszf');
if ($embedHtml !== null) {
    echo $embedHtml;
}

// Or get full document data
$doc = $client->getDocumentByType('privacy');
if ($doc !== null) {
    echo $doc['name'] . ' - Version ' . $doc['lastVersion'];
    echo $client->getDocumentEmbedHtml($doc['embedUrl']);
}
```

## Custom Storage Adapter (PDO Example)

The default `SessionStorage` is session-scoped and not suitable for production. Implement `StorageInterface` for persistent storage:

```php
<?php

use Virtualjog\Contracts\StorageInterface;

class DatabaseStorage implements StorageInterface
{
    private \PDO $pdo;
    private string $table;

    public function __construct(\PDO $pdo, string $table = 'settings')
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare(
            "SELECT `value` FROM `{$this->table}` WHERE `key` = :key LIMIT 1"
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? json_decode($row['value'], true) : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table}` (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = :value2"
        );
        $encoded = json_encode($value);
        $stmt->execute(['key' => $key, 'value' => $encoded, 'value2' => $encoded]);
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM `{$this->table}` WHERE `key` = :key LIMIT 1"
        );
        $stmt->execute(['key' => $key]);

        return $stmt->fetch() !== false;
    }

    public function remove(string $key): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$this->table}` WHERE `key` = :key"
        );
        $stmt->execute(['key' => $key]);
    }
}
```

## License

MIT License
