# LicenseModule

A framework-agnostic PHP module for license validation and tier-based feature gating.

## Features

- Online license validation with configurable server endpoint
- Server-side grace period support (expired license continues working within grace window)
- Offline grace period (7 days default) with cached status
- Hierarchical tier system (higher tiers inherit lower tier modules)
- Addon-based feature unlocking
- Self-contained admin page with tier, addon badges, and validation history
- Module-owned translations (en_US/hu_HU) with optional host translator bridge
- Translatable error views (gettext support)
- Framework-agnostic adapters for database, session, and HTTP

## Requirements

- PHP 8.3+
- PDO extension
- cURL extension
- MySQL/MariaDB database

## Installation

1. Copy the `LicenseModule` folder to your project
2. Run the SQL migration from `schema/migrations.sql`
3. Configure autoloading for the `LicenseModule` namespace

## Quick Start

### Minimal Setup

```php
<?php

use LicenseModule\LicenseModule;

// Initialize with PDO callable (recommended)
$license = new LicenseModule([
    'get_pdo' => fn() => \App\Core\Database::getInstance()->getConnection(),
]);

// Middleware check
$check = $license->checkMiddleware();
if ($check !== null) {
    http_response_code($check['http_code']);
    echo $check['view'];
    exit;
}

// Feature gating
if ($license->hasModule('reports')) {
    // Show reports feature
}
```

### Handling Installation Scenarios

When the database is not yet initialized (e.g., during installation), the PDO factory may return `null`. The module throws a `DatabaseUnavailableException` that you can catch to handle this gracefully:

```php
<?php

use LicenseModule\LicenseModule;
use LicenseModule\Exceptions\DatabaseUnavailableException;

try {
    $license = new LicenseModule([
        'get_pdo' => fn() => $db->getConnection(), // May return null during installation
    ]);

    // Normal license checking...
    $check = $license->checkMiddleware();
    if ($check !== null) {
        http_response_code($check['http_code']);
        echo $check['view'];
        exit;
    }
} catch (DatabaseUnavailableException $e) {
    // Database not ready - allow installation to proceed
    // Host application should show installation wizard
}
```

### Full Configuration

```php
$license = new LicenseModule([
    // Required: PDO connection
    'get_pdo' => fn() => \App\Core\Database::getInstance()->getConnection(),

    // Optional: Custom license server URL
    'server_url' => 'https://lm.patrikmol.com/api/v1/licenses/verify',

    // Optional: Custom tier configuration
    'tiers' => [
        1 => ['name' => 'Basic', 'modules' => ['feature_a', 'feature_b']],
        2 => ['name' => 'Pro', 'modules' => ['feature_c']],  // Inherits tier 1
        3 => ['name' => 'Enterprise', 'modules' => ['feature_d', 'feature_e']],
    ],

    // Optional: Custom addon configuration
    'addons' => [
        'analytics' => ['tracking', 'reports'],
        'api' => ['api_access'],
    ],

    // Optional: Logging callback
    'log_callback' => fn(string $message, string $level) => error_log("[$level] $message"),
]);
```

## API Reference

### Status Checks

```php
// Get current status (active, grace, expired, invalid, suspended)
$status = $license->getStatus();

// Boolean checks
$license->isActive();         // Normal operation (true for both active and grace)
$license->isInGracePeriod();  // Expired but within server-side grace window
$license->isExpired();        // Read-only mode
$license->isSuspended();      // Blocked
$license->isInvalid();        // Blocked
$license->isBlocked();        // Suspended or invalid
```

### License Validation

```php
// Validate license with server
$result = $license->validate($licenseKey, $domain);

if ($result['success']) {
    echo "License valid: " . $result['status'];
} else {
    echo "Validation failed: " . $result['message'];
}

// Check if periodic validation is due
if ($license->isValidationDue()) {
    $license->validate($key, $domain);
}
```

### Feature Gating

```php
// Check single module
if ($license->hasModule('reports')) {
    // Show reports
}

// Get all enabled modules
$modules = $license->getEnabledModules();

// Get tier information
$tier = $license->getTier();  // ['slug' => 'pro', 'name' => 'Pro', 'level' => 4, 'description' => '...']
$level = $license->getTierLevel();  // 4

// Check addons
if ($license->hasAddon('analytics')) {
    // Show analytics features
}
$addons = $license->getEnabledAddons();  // ['analytics', 'mailchimp']

// Full addon rows (feature_key, name, slug, description)
$addonRows = $license->getAddons();

// Tier-only module slugs (excludes addon modules)
$tierModules = $license->getTierModules();

// Newest license row regardless of status (null if no row exists)
$latestInfo = $license->getLatestLicenseInfo();

// Validation history rows, newest first
$history = $license->getValidationHistory(limit: 20);
```

### Middleware Integration

```php
// HTML response for browser requests
$check = $license->checkMiddleware();
if ($check !== null) {
    http_response_code($check['http_code']);
    echo $check['view'];
    exit;
}

// JSON response for API requests
$check = $license->checkMiddlewareJson();
if ($check !== null) {
    http_response_code(403);
    echo json_encode($check);
    exit;
}
```

### License Information

```php
// Get raw license data
$info = $license->getLicenseInfo();

// Get days until expiration
$days = $license->getDaysUntilExpiration();  // null if no expiry, negative if expired

// Grace period information
$graceEnd = $license->getGraceExpiresAt();            // "2026-03-15 00:00:00" or null
$graceDays = $license->getDaysUntilGraceExpiration();  // Days remaining in grace or null
```

## Admin Page

### Overview

The module ships a self-contained admin page that any consuming project can embed in its own admin layout. Call `$licenseModule->renderAdminPage($options)` to get an HTML fragment — no template engine required on the host side.

### Asset Publishing

The admin page requires two asset files to be web-accessible. Copy them from the module's `public/` directory:

```bash
cp lib/LicenseModule/public/license-admin.css public/css/license-admin.css
cp lib/LicenseModule/public/license-admin.js  public/js/license-admin.js
```

Then pass the directory URL as `asset_base_url` when calling `renderAdminPage()`. The module appends `/license-admin.css` and `/license-admin.js` to this prefix automatically.

### Basic Usage

```php
$html = $licenseModule->renderAdminPage([
    'asset_base_url' => '/assets/license',
    'validate_url'   => '/admin/license/validate',
    'csrf_token'     => $csrfToken,
    'locale'         => 'hu_HU',
]);
```

Insert `$html` anywhere inside your admin layout's `<body>`.

### Options Reference

| Option           | Type                 | Default                    | Description                                                                 |
|------------------|----------------------|----------------------------|-----------------------------------------------------------------------------|
| asset_base_url   | string               | `''`                       | URL prefix for CSS/JS assets. Required for assets to load.                  |
| validate_url     | string\|null         | `null`                     | POST endpoint for validation. If null, the Validate button is hidden.       |
| csrf_token       | string\|null         | `null`                     | CSRF token posted by the Validate button AJAX call.                         |
| renew_url        | string               | `https://lm.patrikmol.com` | Renew button link target.                                                   |
| locale           | string               | `en_US`                    | Locale override for this render (`en_US` or `hu_HU`).                      |
| translator       | TranslatorInterface  | `null`                     | Inject a host translator; host strings win over the module's built-ins.     |
| module_names     | array                | `[]`                       | Map of slug → display name for the tier module list.                        |
| date_format      | string               | `Y-m-d`                    | PHP `date()` format for date fields.                                        |
| datetime_format  | string               | `Y-m-d H:i:s`              | PHP `date()` format for datetime fields.                                    |
| history_limit    | int                  | `20`                       | Max validation history rows to show.                                        |

### Security Boundary

> `renderAdminPage()` does **not** enforce authentication, authorization, or CSRF verification — it has no knowledge of the host application's auth system. The host application is solely responsible for protecting the route that calls this method (require login, verify role, validate CSRF before calling `$licenseModule->validate()`).

### Translations

**Built-in:** The module ships `locale/en_US/messages.php` and `locale/hu_HU/messages.php`. Pass the `locale` option to select a language.

**Host bridge:** If the host application manages its own translation system, implement `LicenseModule\Contracts\TranslatorInterface` and pass the instance as the `translator` option. Host-supplied strings take priority over the module's built-ins.

```php
use LicenseModule\Contracts\TranslatorInterface;

class MyTranslator implements TranslatorInterface
{
    public function t(string $key, array $params = []): string
    {
        // Return the translated string, or the raw key to fall back to module's own translation.
        return __($key) ?: $key;
    }
}

$html = $licenseModule->renderAdminPage([
    'translator' => new MyTranslator(),
    // ...other options
]);
```

## Default Tier Configuration

The module includes default tiers based on FlowerShop:

| Level | Name     | Modules |
|-------|----------|---------|
| 1     | Core     | catalog, orders, users, vat_validation, activity_audit, email_templates, favorites |
| 2     | Standard | membership, invoicing, payment_methods, custom_attributes |
| 3     | Advanced | reports, delivery, storage_management |
| 4     | Pro      | supplier, incoming_goods, purchasing |

**Note:** Tiers are hierarchical. A Pro license (level 4) includes all modules from Core, Standard, and Advanced tiers.

## Default Addons

| Addon Key    | Modules       |
|--------------|---------------|
| analytics    | tracking      |
| messageboard | messageboard  |
| mailchimp    | mailchimp     |

## Database Schema

The module requires two tables. Run `schema/migrations.sql` to create them:

- `license_info` - Stores license key, status, tier, addons, and validation timestamps
- `license_validation_history` - Logs all validation attempts for auditing

## Translations

The module includes PO files for English and Hungarian in the `locale/` folder.

### Translation Keys

- `LICENSE_GRACE_TITLE`
- `LICENSE_GRACE_MESSAGE`
- `LICENSE_GRACE_NOTICE`
- `LICENSE_EXPIRED_TITLE`
- `LICENSE_EXPIRED_MESSAGE`
- `LICENSE_EXPIRED_READONLY`
- `LICENSE_SUSPENDED_TITLE`
- `LICENSE_SUSPENDED_MESSAGE`
- `LICENSE_SUSPENDED_NOTICE`
- `LICENSE_CONTACT_SUPPORT`
- `LICENSE_INVALID_MESSAGE`

### Using Translations

The module uses gettext's `_()` function if available. To use translations:

1. Copy PO files from `locale/` to your project's locale directory
2. Compile PO files to MO format using `msgfmt`
3. Configure gettext in your application

## Custom Adapters

### Custom Database Adapter

> **Breaking change:** `DatabaseAdapterInterface` now requires five methods. Custom adapters written against the original three-method interface must be updated to add `getLatestLicenseInfo()` and `getValidationHistory()`.

```php
use LicenseModule\Contracts\DatabaseAdapterInterface;

class MyDatabaseAdapter implements DatabaseAdapterInterface
{
    public function getLicenseInfo(): ?array { /* ... */ }
    public function saveLicenseInfo(array $data): bool { /* ... */ }
    public function logValidation(int $licenseId, string $status, array $responseData = [], string $errorMessage = ''): bool { /* ... */ }

    /** Return the newest license row regardless of status, or null if the table is empty. */
    public function getLatestLicenseInfo(): ?array { /* ... */ }

    /** Return validation history rows (newest first), limited to $limit entries. */
    public function getValidationHistory(int $limit = 20): array { /* ... */ }
}

$license = new LicenseModule([
    'database_adapter' => new MyDatabaseAdapter(),
]);
```

### Custom Session Adapter

```php
use LicenseModule\Contracts\SessionAdapterInterface;

class MySessionAdapter implements SessionAdapterInterface
{
    public function get(string $key, mixed $default = null): mixed { /* ... */ }
    public function set(string $key, mixed $value): void { /* ... */ }
    public function has(string $key): bool { /* ... */ }
    public function remove(string $key): void { /* ... */ }
}

$license = new LicenseModule([
    'get_pdo' => fn() => $pdo,
    'session_adapter' => new MySessionAdapter(),
]);
```

### CLI / Cron Usage (MemorySessionAdapter)

In CLI scripts and cron jobs `session_start()` is unavailable. Use the bundled `MemorySessionAdapter` instead of the default `NativeSessionAdapter`:

```php
use LicenseModule\LicenseModule;
use LicenseModule\Adapters\Session\MemorySessionAdapter;

$license = new LicenseModule([
    'get_pdo'         => fn() => $pdo,
    'session_adapter' => new MemorySessionAdapter(),
]);
```

The adapter stores values in a plain PHP array for the lifetime of the current process. Values are not persisted between runs — each CLI invocation starts with an empty state.

### Custom HTTP Client

```php
use LicenseModule\Contracts\HttpClientInterface;

class MyHttpClient implements HttpClientInterface
{
    public function post(string $url, array $data, array $headers = [], int $timeout = 10): array
    {
        // Return: ['success' => bool, 'status_code' => int, 'body' => string|null, 'error' => string|null]
    }
}

$license = new LicenseModule([
    'get_pdo' => fn() => $pdo,
    'http_client' => new MyHttpClient(),
]);
```

## License Server API

The module expects the license server to return responses in this format:

**Supported status values:** `valid`, `active`, `grace`, `revoked`, `expired`, `invalid`

```json
{
  "success": true,
  "data": {
    "valid": true,
    "status": "valid",
    "message": "License is valid",
    "expiry_date": "2026-01-01 00:00:00",
    "client_name": "Acme Corp",
    "in_grace_period": false,
    "grace_expires_at": null,
    "package": {
      "id": 1,
      "name": "Pro Suite",
      "slug": "pro-suite"
    },
    "tier": {
      "slug": "pro",
      "name": "Professional",
      "level": 4,
      "description": "Full feature access"
    },
    "addons": [
      {
        "feature_key": "analytics",
        "name": "Advanced Analytics",
        "slug": "analytics",
        "description": "Tracking and reporting"
      }
    ],
    "features": ["basic_support", "api_access", "analytics"]
  }
}
```

When a license is in grace period, the server returns `"valid": true` with `"status": "grace"`, `"in_grace_period": true` and `"grace_expires_at": "2026-02-15 00:00:00"`.

## Rate Limiting (429 Too Many Requests)

When the license server returns `429 Too Many Requests`, the module returns a `throttled` result instead of treating the situation as a server outage:

```php
$result = $license->validate($key, $domain);

if (!empty($result['throttled'])) {
    // Server is reachable but rate-limiting this client.
    // Do NOT treat this as a license failure — just retry later.
}
```

Key behaviour:

- `$result['status']` is `LicenseStatus::THROTTLED` (`'throttled'`)
- `$result['throttled']` is `true`
- `last_check_at` is **not** updated, so the next scheduled check will retry immediately
- The offline grace period is **not** consumed — the server is reachable, only temporarily throttled

This requires a license server running v2.8.0 or later, which replaced the file-based rate limiter with a DB-backed one that correctly returns `429`.

## Grace Periods

The module supports two independent grace period concepts:

### Server-Side Grace Period

When a license expires on the server, the server may grant a configurable grace period during which the license remains fully operational. The server returns `status: "grace"` with a `grace_expires_at` date. Use `isInGracePeriod()` to detect this state and warn users to renew.

### Offline Grace Period

When the license server is unreachable:

1. The module uses the last known license status
2. After 7 days without successful validation, the system enters read-only mode
3. Validation attempts are logged for debugging

## License

MIT License
