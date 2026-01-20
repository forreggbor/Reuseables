# ActivityLogger

Framework-agnostic PHP activity logging for tracking any application events with flexible schema and integrity verification.

## Features

- **Fully Flexible**: No predefined actions, entity types, or sources - log anything
- **Sensitive Data Masking**: Automatically masks passwords, tokens, API keys
- **Change Tracking**: Only stores actual changes (filters unchanged values)
- **Integrity Verification**: SHA-256 checksum for tamper detection
- **Auto-Detection**: Source and IP address auto-detected if not provided
- **Session Tracking**: Groups related actions by PHP session
- **Context Support**: Store any additional structured data
- **Query Methods**: Filter by user, entity, action, source, date range
- **Statistics**: Activity trends, unique actions, cleanup tools
- **Self-Contained**: Single file, no dependencies except PDO

## Requirements

- PHP 8.3+
- PDO extension
- MySQL 5.7+ or MariaDB 10.2+

## Installation

1. Copy the `ActivityLogs` folder to your project
2. Run `schema.sql` to create the database table
3. Include or autoload `ActivityLogger.php`

## Quick Start

```php
<?php

require_once 'ActivityLogs/ActivityLogger.php';

use ActivityLogs\ActivityLogger;

// Initialize with PDO connection
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'password');
ActivityLogger::init($pdo, [
    'encryption_key' => 'your-secret-key-for-checksums',
]);

// Log any activity
ActivityLogger::log(
    userId: 123,
    action: 'create_product',
    entityType: 'product',
    entityId: '456',
    newValues: ['name' => 'Rose Bouquet', 'price' => 2500]
);

// Log with context
ActivityLogger::log(
    userId: 1,
    action: 'export_report',
    context: ['format' => 'pdf', 'rows' => 1500, 'filters' => ['date' => '2026-01']]
);

// Log system action (no user)
ActivityLogger::log(
    userId: null,
    action: 'daily_backup_completed',
    context: ['size_mb' => 245, 'duration_seconds' => 32]
);
```

## Configuration

### Full Configuration Example

```php
ActivityLogger::init($pdo, [
    // Required for integrity verification
    'encryption_key' => 'your-secret-key',

    // Optional: custom table name
    'table_name' => 'activity_logs',

    // Optional: additional sensitive fields to mask
    'sensitive_fields' => [
        'password', 'password_hash', 'api_key', 'token', 'secret',
        'credit_card', 'cvv', 'remember_token', 'csrf_token',
        'encryption_key', 'private_key', 'access_token', 'refresh_token',
        // Add your own...
    ],
]);

// Add sensitive fields at runtime
ActivityLogger::addSensitiveFields(['custom_secret', 'pin_code']);
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `encryption_key` | string | `'activity_log_default_key'` | Key for checksum generation |
| `table_name` | string | `'activity_logs'` | Database table name |
| `sensitive_fields` | array | (see above) | Fields to mask with `***MASKED***` |

## API Reference

### Logging

#### log()

Log any activity. Only `action` is required - all other parameters are optional.

```php
ActivityLogger::log(
    userId: ?int,              // User performing action (null for system)
    action: string,            // REQUIRED: action name
    entityType: ?string,       // What was affected
    entityId: string|int|null, // Entity identifier (any format)
    oldValues: ?array,         // Previous state
    newValues: ?array,         // New state
    source: ?string,           // Source (auto-detected if null)
    context: ?array,           // Any additional data
    ipAddress: ?string,        // Client IP (auto-detected if null)
    userAgent: ?string         // User agent (auto-detected if null)
): int|false;                  // Returns log ID or false
```

### Logging Examples

```php
// Create operation
ActivityLogger::log(1, 'create_product', 'product', 100, null, ['name' => 'Rose']);

// Update operation (only changed values are stored)
ActivityLogger::log(1, 'update_product', 'product', 100,
    ['name' => 'Rose', 'price' => 100],
    ['name' => 'Rose', 'price' => 150]  // Only price change is stored
);

// Delete operation
ActivityLogger::log(1, 'delete_product', 'product', 100, ['name' => 'Rose'], null);

// Login (no entity)
ActivityLogger::log(1, 'user_login', source: 'mobile_app');

// Failed login (with context)
ActivityLogger::log(null, 'failed_login', 'user', 'john@example.com',
    context: ['reason' => 'invalid_password', 'attempts' => 3]
);

// System action
ActivityLogger::log(null, 'cron_cleanup', context: ['deleted_rows' => 150]);

// API call
ActivityLogger::log(1, 'api_request', 'endpoint', '/users/123',
    context: ['method' => 'GET', 'response_code' => 200]
);

// Any custom action
ActivityLogger::log(5, 'uploaded_document', 'document', 'uuid-1234-5678',
    newValues: ['filename' => 'report.pdf', 'size' => 1024000],
    source: 'admin',
    context: ['mime_type' => 'application/pdf']
);
```

### Query Methods

#### getAll()

Get logs with filters and pagination.

```php
$logs = ActivityLogger::getAll([
    'user_id' => 123,
    'action' => 'update_product',
    'entity_type' => 'product',
    'entity_id' => '456',
    'source' => 'admin',
    'date_from' => '2026-01-01',
    'date_to' => '2026-01-31',
    'search' => 'product',      // Searches action and entity_type
    'limit' => 50,
    'offset' => 0,
]);
```

#### getByUser()

Get logs for a specific user.

```php
$logs = ActivityLogger::getByUser(userId: 123, limit: 50);
```

#### getByEntity()

Get logs for a specific entity.

```php
$logs = ActivityLogger::getByEntity(
    entityType: 'product',
    entityId: '456',
    limit: 50
);
```

#### getBySession()

Get logs from a specific session.

```php
$logs = ActivityLogger::getBySession(sessionId: 'abc123...', limit: 100);
```

#### findById()

Get a single log entry.

```php
$log = ActivityLogger::findById(12345);
```

#### getCount()

Get count with same filters as getAll().

```php
$count = ActivityLogger::getCount(['user_id' => 123]);
```

### Statistics

#### getStatistics()

Get summary statistics.

```php
$stats = ActivityLogger::getStatistics([
    'date_from' => '2026-01-01',
    'date_to' => '2026-01-31',
]);
// Returns: total, unique_users, unique_entity_types, unique_actions, unique_sources, today, this_week
```

#### getActivityTrend()

Get daily activity counts for charts.

```php
$trend = ActivityLogger::getActivityTrend(days: 30);
// Returns: [{date: '2026-01-01', count: 150}, ...]
```

#### getUniqueActions(), getUniqueEntityTypes(), getUniqueSources()

Get lists of all logged values (useful for filter dropdowns).

```php
$actions = ActivityLogger::getUniqueActions();
$types = ActivityLogger::getUniqueEntityTypes();
$sources = ActivityLogger::getUniqueSources();
```

### Maintenance

#### deleteOldLogs()

Remove old log entries.

```php
$deleted = ActivityLogger::deleteOldLogs(days: 90);
echo "Deleted {$deleted} old log entries";
```

### Integrity Verification

#### verifyIntegrity()

Verify a log entry hasn't been tampered with.

```php
$isValid = ActivityLogger::verifyIntegrity(logId: 12345);
if (!$isValid) {
    // Log entry may have been modified!
}
```

## Instance Mode

Create multiple loggers with different configurations.

```php
// Main application logger
$appLogger = new ActivityLogger($pdo, [
    'encryption_key' => 'app-key',
    'table_name' => 'app_activity_logs',
]);

// Separate audit logger for sensitive operations
$auditLogger = new ActivityLogger($pdo, [
    'encryption_key' => 'audit-key',
    'table_name' => 'security_audit_logs',
]);

$appLogger->write(1, 'view_dashboard');
$auditLogger->write(1, 'access_sensitive_data', 'customer', 456);
```

## Log Entry Format

Log entries are stored with the following structure:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Auto-increment primary key |
| `user_id` | INT NULL | User who performed action |
| `source` | VARCHAR(50) | Source: admin, frontend, api, cli, etc. |
| `action` | VARCHAR(100) | Action name (required) |
| `entity_type` | VARCHAR(100) | Entity type (optional) |
| `entity_id` | VARCHAR(100) | Entity identifier (optional) |
| `old_values` | JSON | Previous state |
| `new_values` | JSON | New state |
| `context` | JSON | Additional data |
| `ip_address` | VARCHAR(45) | Client IP |
| `user_agent` | VARCHAR(500) | Browser user agent |
| `session_id` | VARCHAR(64) | PHP session ID |
| `checksum` | VARCHAR(64) | SHA-256 integrity hash |
| `created_at` | TIMESTAMP | When action occurred |

## Integration Examples

### Bootstrap Integration

```php
// bootstrap.php
require_once 'ActivityLogs/ActivityLogger.php';

use ActivityLogs\ActivityLogger;

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

ActivityLogger::init($pdo, [
    'encryption_key' => $_ENV['APP_KEY'],
]);
```

### MVC Controller

```php
class ProductController
{
    public function update(int $id, array $data): array
    {
        $oldProduct = $this->productModel->find($id);

        $this->productModel->update($id, $data);

        ActivityLogger::log(
            userId: Auth::id(),
            action: 'update_product',
            entityType: 'product',
            entityId: $id,
            oldValues: (array)$oldProduct,
            newValues: $data
        );

        return ['success' => true];
    }
}
```

### Service Layer

```php
class OrderService
{
    public function createOrder(array $orderData): Order
    {
        $order = $this->orderRepository->create($orderData);

        ActivityLogger::log(
            userId: $orderData['user_id'],
            action: 'create_order',
            entityType: 'order',
            entityId: $order->id,
            newValues: $orderData,
            context: [
                'total' => $order->total,
                'items_count' => count($orderData['items']),
            ]
        );

        return $order;
    }
}
```

### Authentication

```php
class AuthService
{
    public function login(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user || !password_verify($password, $user->password_hash)) {
            ActivityLogger::log(
                userId: null,
                action: 'failed_login',
                entityType: 'user',
                entityId: $email,
                context: ['reason' => $user ? 'invalid_password' : 'user_not_found']
            );
            return null;
        }

        ActivityLogger::log(
            userId: $user->id,
            action: 'user_login',
            source: 'frontend'
        );

        return $user;
    }
}
```

## License

MIT License
