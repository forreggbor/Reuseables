# DotEnv

Lightweight, framework-agnostic PHP `.env` file parser. A standalone replacement for `vlucas/phpdotenv` with zero external dependencies.

## Features

- Parses `KEY=value` syntax including quoted values and inline comments
- Immutable mode: never overwrites existing `$_ENV` values
- `load()` — throws `RuntimeException` if file is missing
- `safeLoad()` — silently returns empty array if file is missing
- `required()->notEmpty()` — validation chain for mandatory variables
- Populates only `$_ENV` (no `$_SERVER`, no `putenv()`)
- PHP 8.3+

## Installation

Copy this module to your project's `lib/` folder:

```bash
rsync -av --delete ~/development/reusables/DotEnv/ lib/DotEnv/
```

Add the namespace to your `composer.json` autoload:

```json
"autoload": {
    "psr-4": {
        "DotEnv\\": "lib/DotEnv/"
    }
}
```

## Usage

### Basic loading

```php
use DotEnv\DotEnv;

// Throws RuntimeException if .env is missing
DotEnv::createImmutable('/path/to/project')->load();

// Silent — returns [] if .env is missing
DotEnv::createImmutable('/path/to/project')->safeLoad();

// Custom filename
DotEnv::createImmutable('/path/to/project', '.env.local')->load();
```

### Validation

```php
$dotenv = DotEnv::createImmutable(ROOT_PATH);
$dotenv->load();
$dotenv->required(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'])->notEmpty();
```

### Accessing values

```php
$host = $_ENV['DB_HOST'] ?? 'localhost';
```

## .env File Syntax

```ini
# This is a comment
APP_ENV=production
APP_NAME="My Application"
APP_DEBUG=false

# Double-quoted: supports \n \t \\ escape sequences
DESCRIPTION="Line one\nLine two"

# Single-quoted: no escape processing
PATTERN='value with "quotes" inside'

# Inline comment (must be preceded by space)
TIMEOUT=30 # seconds

# export prefix is stripped
export DB_HOST=localhost

# Values may contain = signs
ENCRYPTION_KEY=base64encoded==value
```

## Migration from vlucas/phpdotenv

Replace:
```php
$dotenv = Dotenv\Dotenv::createImmutable(ROOT_PATH);
$dotenv->load();
```

With:
```php
use DotEnv\DotEnv;
$dotenv = DotEnv::createImmutable(ROOT_PATH);
$dotenv->load();
```

Replace:
```php
$dotenv->required(['DB_HOST'])->notEmpty();
```
With (same API):
```php
$dotenv->required(['DB_HOST'])->notEmpty();
```

Replace `safeLoad()`:
```php
$dotenv->safeLoad();  // identical method name
```

Remove from `composer.json`:
```json
"vlucas/phpdotenv": "^5.5"
```

## Unsupported Features

The following vlucas/phpdotenv features are intentionally not supported (not used by any project in this ecosystem):

- Variable interpolation (`${OTHER_VAR}`)
- `->required()->isInteger()` / `->isBoolean()` / `->allowedValues()`
- Mutable mode (`createMutable()`)
- `$_SERVER` population
- `putenv()` calls
- Multi-file loading (beyond custom filename parameter)
