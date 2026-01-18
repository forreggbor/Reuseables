# LicenseModule

A framework-agnostic PHP module for license validation and tier-based feature gating.

## Features

- Online license validation with configurable server endpoint
- Offline grace period (7 days default) with cached status
- Hierarchical tier system (higher tiers inherit lower tier modules)
- Addon-based feature unlocking
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
// Get current status (active, expired, invalid, suspended)
$status = $license->getStatus();

// Boolean checks
$license->isActive();     // Normal operation
$license->isExpired();    // Read-only mode
$license->isSuspended();  // Blocked
$license->isInvalid();    // Blocked
$license->isBlocked();    // Suspended or invalid
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
$tier = $license->getTier();  // ['slug' => 'pro', 'name' => 'Pro', 'level' => 4]
$level = $license->getTierLevel();  // 4

// Check addons
if ($license->hasAddon('analytics')) {
    // Show analytics features
}
$addons = $license->getEnabledAddons();  // ['analytics', 'mailchimp']
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
```

## Default Tier Configuration

The module includes default tiers based on FlowerShop:

| Level | Name     | Modules |
|-------|----------|---------|
| 1     | Core     | catalog, orders, users, vat_validation, activity_audit, email_templates, favorites |
| 2     | Standard | membership, invoicing, payment_methods, custom_attributes |
| 3     | Advanced | reports |
| 4     | Pro      | delivery, storage_management |

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

```php
use LicenseModule\Contracts\DatabaseAdapterInterface;

class MyDatabaseAdapter implements DatabaseAdapterInterface
{
    public function getLicenseInfo(): ?array { /* ... */ }
    public function saveLicenseInfo(array $data): bool { /* ... */ }
    public function logValidation(int $licenseId, string $status, array $responseData = [], string $errorMessage = ''): bool { /* ... */ }
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

**Supported status values:** `valid`, `active`, `revoked`, `expired`, `invalid`

```json
{
  "success": true,
  "data": {
    "valid": true,
    "status": "valid",
    "message": "License is valid",
    "expiry_date": "2026-01-01 00:00:00",
    "client_name": "Acme Corp",
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

## Offline Mode

When the license server is unreachable:

1. The module uses the last known license status
2. After 7 days without successful validation, the system enters read-only mode
3. Validation attempts are logged for debugging

## License

MIT License
