# ErrorHandler

Framework-agnostic PHP error and exception logging with configurable severity levels.

## Features

- **Severity Levels**: ERROR, WARNING, INFO, DEBUG with priority-based filtering
- **Dual Usage**: Static methods for quick use, instance mode for multiple loggers
- **Context Support**: Attach structured data to log entries
- **PHP Handler Registration**: Optional error, exception, and shutdown handlers
- **Self-Contained**: No external dependencies, single file
- **File Locking**: Concurrent-safe writes with `LOCK_EX`
- **Auto-Directory Creation**: Creates log directory if it doesn't exist
- **Fallback Logging**: Falls back to PHP's `error_log()` if file write fails

## Requirements

- PHP 8.3+

## Installation

1. Copy the `ErrorHandling` folder to your project
2. Include or autoload `ErrorHandler.php`

## Quick Start

```php
<?php

require_once 'ErrorHandling/ErrorHandler.php';

use ErrorHandling\ErrorHandler;

// Initialize with configuration
ErrorHandler::init([
    'log_path' => __DIR__ . '/storage/logs',
    'log_level' => 'DEBUG',
]);

// Log messages
ErrorHandler::error('Database connection failed');
ErrorHandler::warning('Cache miss for key: user_123');
ErrorHandler::info('User logged in');
ErrorHandler::debug('Query executed: SELECT * FROM users');

// Log with context
ErrorHandler::error('Order processing failed', [
    'order_id' => 12345,
    'user_id' => 67,
]);
```

## Configuration

### Full Configuration Example

```php
ErrorHandler::init([
    // Log file location
    'log_path' => '/var/www/myapp/storage/logs',  // Directory for log files
    'log_file' => 'error.log',                     // Log filename

    // Filtering
    'log_level' => 'WARNING',  // Minimum level: ERROR, WARNING, INFO, DEBUG

    // Formatting
    'date_format' => 'Y-m-d H:i:s',  // Timestamp format

    // Debug options
    'include_trace' => false,  // Include stack traces for exceptions

    // Permissions
    'permissions' => 0755,     // Directory permissions on creation
]);
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `log_path` | string | `/storage/logs` | Directory for log files |
| `log_file` | string | `error.log` | Log filename |
| `log_level` | string | `ERROR` | Minimum level to log |
| `date_format` | string | `Y-m-d H:i:s` | Timestamp format |
| `include_trace` | bool | `false` | Include stack trace for exceptions |
| `permissions` | int | `0755` | Directory permissions on creation |

### Log Levels

Levels are filtered by priority (lower = higher priority):

| Level | Priority | Description |
|-------|----------|-------------|
| `ERROR` | 1 | Critical errors that need immediate attention |
| `WARNING` | 2 | Non-critical issues that should be reviewed |
| `INFO` | 3 | General operational information |
| `DEBUG` | 4 | Detailed debugging information |

Setting `log_level` to `WARNING` will log ERROR and WARNING messages, but skip INFO and DEBUG.

## API Reference

### Static Methods

#### init()

Initialize with configuration. Call once at application bootstrap.

```php
ErrorHandler::init([
    'log_path' => '/path/to/logs',
    'log_level' => 'DEBUG',
]);
```

#### log()

Log a message with specified level.

```php
ErrorHandler::log('Message', 'ERROR', ['key' => 'value']);
```

#### error(), warning(), info(), debug()

Convenience methods for each log level.

```php
ErrorHandler::error('Critical failure');
ErrorHandler::warning('Disk space low');
ErrorHandler::info('User registered');
ErrorHandler::debug('Cache hit ratio: 0.95');
```

#### logException()

Log an exception with full details.

```php
try {
    // risky operation
} catch (\Exception $e) {
    ErrorHandler::logException($e, 'ERROR', ['operation' => 'import']);
}
```

#### setLogLevel(), setLogPath(), setLogFile()

Change configuration at runtime.

```php
ErrorHandler::setLogLevel('DEBUG');
ErrorHandler::setLogPath('/new/path/logs');
ErrorHandler::setLogFile('app.log');
```

#### isLevelEnabled()

Check if a level would be logged.

```php
if (ErrorHandler::isLevelEnabled('DEBUG')) {
    $debugData = expensiveDebugOperation();
    ErrorHandler::debug('Debug info', $debugData);
}
```

#### getLogFilePath()

Get the full path to the current log file.

```php
$path = ErrorHandler::getLogFilePath();
// Returns: /storage/logs/error.log
```

### PHP Handler Registration

Register ErrorHandler to catch PHP errors and exceptions automatically.

#### registerErrorHandler()

Convert PHP errors to log entries.

```php
ErrorHandler::registerErrorHandler();

// Now PHP warnings, notices, etc. are logged
trigger_error('Something happened', E_USER_WARNING);
// Logged as: [WARNING] Something happened in /path/file.php:123
```

#### registerExceptionHandler()

Catch uncaught exceptions.

```php
ErrorHandler::registerExceptionHandler();

// Uncaught exceptions are logged before termination
throw new \RuntimeException('Unhandled error');
// Logged as: [ERROR] Uncaught RuntimeException: Unhandled error in /path/file.php:123
```

#### registerShutdownHandler()

Catch fatal errors on shutdown.

```php
ErrorHandler::registerShutdownHandler();

// Fatal errors are logged before script termination
```

#### registerAllHandlers()

Register all handlers at once.

```php
ErrorHandler::registerAllHandlers();
```

#### Restore Handlers

```php
ErrorHandler::restoreErrorHandler();
ErrorHandler::restoreExceptionHandler();
```

### Instance Mode

Create separate logger instances with different configurations.

```php
// Main application logger
$appLogger = new ErrorHandler([
    'log_path' => '/var/log/myapp',
    'log_file' => 'app.log',
    'log_level' => 'INFO',
]);

// Separate audit logger
$auditLogger = new ErrorHandler([
    'log_path' => '/var/log/myapp',
    'log_file' => 'audit.log',
    'log_level' => 'DEBUG',
]);

$appLogger->write('Application started', 'INFO');
$auditLogger->write('Admin logged in', 'INFO', ['user_id' => 1]);
```

## Log Format

Log entries follow this format:

```
[TIMESTAMP] [LEVEL] message {context_json}
```

Examples:

```
[2026-01-20 12:35:00] [ERROR] Database connection failed
[2026-01-20 12:35:01] [INFO] User logged in {"user_id":42,"ip":"192.168.1.1"}
[2026-01-20 12:35:02] [WARNING] Cache miss {"key":"user_session_123"}
[2026-01-20 12:35:03] [DEBUG] Query executed {"sql":"SELECT * FROM users","time_ms":15}
```

## Integration Examples

### Bootstrap Integration

```php
// bootstrap.php
require_once 'ErrorHandling/ErrorHandler.php';

use ErrorHandling\ErrorHandler;

// Initialize early in bootstrap
ErrorHandler::init([
    'log_path' => ROOT_PATH . '/storage/logs',
    'log_level' => ($_ENV['APP_DEBUG'] ?? false) ? 'DEBUG' : 'ERROR',
    'include_trace' => ($_ENV['APP_DEBUG'] ?? false),
]);

// Register handlers for comprehensive error catching
if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
    ErrorHandler::registerAllHandlers();
}
```

### MVC Controller

```php
class OrderController
{
    public function create(array $data): array
    {
        ErrorHandler::debug('Creating order', ['data' => $data]);

        try {
            $order = $this->orderService->create($data);
            ErrorHandler::info('Order created', ['order_id' => $order->id]);

            return ['success' => true, 'order_id' => $order->id];

        } catch (\Exception $e) {
            ErrorHandler::logException($e, 'ERROR', ['data' => $data]);

            return ['success' => false, 'error' => 'Order creation failed'];
        }
    }
}
```

### Service Layer

```php
class PaymentService
{
    public function process(int $orderId, float $amount): bool
    {
        ErrorHandler::info('Processing payment', [
            'order_id' => $orderId,
            'amount' => $amount,
        ]);

        try {
            $result = $this->gateway->charge($amount);

            if (!$result->success) {
                ErrorHandler::warning('Payment declined', [
                    'order_id' => $orderId,
                    'reason' => $result->declineReason,
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            ErrorHandler::logException($e, 'ERROR', ['order_id' => $orderId]);
            return false;
        }
    }
}
```

### Conditional Debug Logging

```php
// Avoid expensive operations when debug is disabled
if (ErrorHandler::isLevelEnabled('DEBUG')) {
    $debugInfo = [
        'memory_peak' => memory_get_peak_usage(true),
        'query_count' => $db->getQueryCount(),
        'cache_stats' => $cache->getStats(),
    ];
    ErrorHandler::debug('Request completed', $debugInfo);
}
```

## License

MIT License
