# GettextFallback

A PHP component that provides gettext translation functionality with automatic fallback for servers where system locales are not installed (common on shared hosting).

## Features

- **Automatic Locale Detection** - Detects if system locale is available
- **Custom MO Parser** - Binary parser for .mo files when native gettext unavailable
- **Transparent Fallback** - Uses native gettext when possible, custom parser when not
- **Full Plural Support** - Parses and evaluates plural form expressions
- **Multiple Domains** - Support for textdomain/bindtextdomain
- **Context Support** - Handle pgettext-style contextual translations
- **In-Memory Caching** - Caches parsed translations for performance
- **Global Function Wrappers** - Optional drop-in replacement for native functions

## Requirements

- PHP 8.3+
- Standard gettext .mo files in `/locale/{lang}/LC_MESSAGES/` structure

## Installation

Copy the `GettextFallback/` folder to your project.

## Quick Start

```php
<?php
require_once 'GettextFallback/GettextFallback.php';
require_once 'GettextFallback/functions.php'; // Optional: provides _() function

use GettextFallback\GettextFallback;

// Initialize
GettextFallback::init([
    'locale_path' => __DIR__ . '/locale',
]);

// Set locale and bind domain
GettextFallback::setLocale('hu_HU');
GettextFallback::bindDomain('messages', __DIR__ . '/locale');
GettextFallback::setDomain('messages');

// Translate
echo _('Hello, World!');
```

## Configuration

```php
GettextFallback::init([
    'locale_path' => '/locale',           // Base path for locale files
    'default_domain' => 'messages',       // Default text domain
    'default_locale' => 'en_US',          // Fallback locale
    'use_native_if_available' => true,    // Use native gettext when locale available
    'cache_translations' => true,         // Cache parsed translations in memory
]);
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `locale_path` | string | `'/locale'` | Base path for locale files |
| `default_domain` | string | `'messages'` | Default text domain |
| `default_locale` | string | `'en_US'` | Fallback locale when none set |
| `use_native_if_available` | bool | `true` | Use native gettext when locale is installed |
| `cache_translations` | bool | `true` | Cache parsed translations in memory |

## API Reference

### Initialization

#### `init(array $config = []): void`
Initialize the component with configuration options.

```php
GettextFallback::init([
    'locale_path' => __DIR__ . '/locale',
    'default_domain' => 'messages',
]);
```

#### `getConfig(): array`
Get current configuration.

### Locale & Domain Management

#### `setLocale(string $locale): bool`
Set the current locale. Returns true on success.

```php
GettextFallback::setLocale('hu_HU');
GettextFallback::setLocale('de_DE');
```

#### `getLocale(): string`
Get the current locale code.

#### `setDomain(string $domain): void`
Set the current text domain.

```php
GettextFallback::setDomain('messages');
GettextFallback::setDomain('errors');
```

#### `getDomain(): string`
Get the current text domain.

#### `bindDomain(string $domain, string $path): void`
Bind a text domain to a directory path.

```php
GettextFallback::bindDomain('messages', __DIR__ . '/locale');
GettextFallback::bindDomain('admin', __DIR__ . '/admin/locale');
```

### Translation Methods

#### `translate(string $msgid): string`
Translate a string. Equivalent to `_()` or `gettext()`.

```php
echo GettextFallback::translate('Hello, World!');
```

#### `nTranslate(string $singular, string $plural, int $n): string`
Translate with plural forms. Equivalent to `ngettext()`.

```php
echo GettextFallback::nTranslate('%d item', '%d items', $count);
```

#### `dTranslate(string $domain, string $msgid): string`
Translate from a specific domain. Equivalent to `dgettext()`.

```php
echo GettextFallback::dTranslate('errors', 'Invalid input');
```

#### `dnTranslate(string $domain, string $singular, string $plural, int $n): string`
Translate plural from a specific domain. Equivalent to `dngettext()`.

```php
echo GettextFallback::dnTranslate('shop', '%d product', '%d products', $count);
```

#### `pTranslate(string $context, string $msgid): string`
Translate with context for disambiguation. Equivalent to `pgettext()`.

```php
// "Open" as in "Open file" vs "Open" as in "Open door"
echo GettextFallback::pTranslate('file', 'Open');
echo GettextFallback::pTranslate('door', 'Open');
```

#### `npTranslate(string $context, string $singular, string $plural, int $n): string`
Translate plural with context. Equivalent to `npgettext()`.

### Status Methods

#### `isUsingNativeGettext(): bool`
Check if native gettext is being used for the current locale.

```php
if (GettextFallback::isUsingNativeGettext()) {
    echo "Using native gettext";
} else {
    echo "Using fallback MO parser";
}
```

#### `isLocaleAvailable(string $locale): bool`
Check if a locale is available on the system.

```php
if (GettextFallback::isLocaleAvailable('hu_HU')) {
    echo "Hungarian locale is installed";
}
```

#### `clearCache(?string $domain = null): void`
Clear the translation cache. Pass domain name to clear specific domain only.

```php
GettextFallback::clearCache();           // Clear all
GettextFallback::clearCache('messages'); // Clear specific domain
```

#### `getBoundDomains(): array`
Get list of bound domains with their paths.

## Global Function Wrappers

Include `functions.php` to get drop-in replacements for native gettext functions:

```php
require_once 'GettextFallback/functions.php';

// These work like native gettext functions
echo _('Hello');
echo gettext('Hello');
echo ngettext('%d item', '%d items', $count);
echo dgettext('errors', 'Invalid');
echo dngettext('shop', '%d product', '%d products', $count);
echo pgettext('menu', 'File');
echo npgettext('menu', '%d file', '%d files', $count);

// Domain management
textdomain('messages');
bindtextdomain('messages', '/path/to/locale');
```

The functions are only defined if they don't already exist, so native gettext takes precedence.

## Directory Structure

Your locale files should follow the standard gettext structure:

```
project/
├── locale/
│   ├── hu_HU/
│   │   └── LC_MESSAGES/
│   │       ├── messages.mo
│   │       └── messages.po
│   ├── en_US/
│   │   └── LC_MESSAGES/
│   │       ├── messages.mo
│   │       └── messages.po
│   └── de_DE/
│       └── LC_MESSAGES/
│           ├── messages.mo
│           └── messages.po
└── ...
```

## MO File Compilation

Create your translations in .po files and compile them to .mo:

```bash
msgfmt messages.po -o messages.mo
```

Or use tools like Poedit which compile automatically.

## Plural Forms

The component parses the `Plural-Forms` header from .mo files and evaluates the expression. Common examples:

```
# Hungarian (2 forms)
Plural-Forms: nplurals=2; plural=(n != 1);

# English, German (2 forms)
Plural-Forms: nplurals=2; plural=(n != 1);

# French (2 forms, different rule)
Plural-Forms: nplurals=2; plural=(n > 1);

# Russian (3 forms)
Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);

# Polish (3 forms)
Plural-Forms: nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);
```

## Integration Example

### Bootstrap Integration

```php
<?php
// bootstrap.php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/GettextFallback/GettextFallback.php';
require_once __DIR__ . '/GettextFallback/functions.php';

use GettextFallback\GettextFallback;

// Initialize translations
GettextFallback::init([
    'locale_path' => __DIR__ . '/locale',
    'default_domain' => 'messages',
    'default_locale' => 'en_US',
]);

// Set locale from user preference or session
$userLocale = $_SESSION['locale'] ?? 'en_US';
GettextFallback::setLocale($userLocale);
GettextFallback::bindDomain('messages', __DIR__ . '/locale');
GettextFallback::setDomain('messages');
```

### MVC Controller Integration

```php
<?php

class BaseController
{
    protected function setLanguage(string $locale): void
    {
        GettextFallback::setLocale($locale);
        $_SESSION['locale'] = $locale;
    }

    protected function t(string $msgid): string
    {
        return GettextFallback::translate($msgid);
    }

    protected function tn(string $singular, string $plural, int $n): string
    {
        return GettextFallback::nTranslate($singular, $plural, $n);
    }
}
```

### View/Template Usage

```php
<!-- In your templates -->
<h1><?= _('Welcome') ?></h1>
<p><?= sprintf(ngettext('%d item in cart', '%d items in cart', $count), $count) ?></p>
<button><?= pgettext('button', 'Submit') ?></button>
```

## Troubleshooting

### Translations not showing

1. Check that the .mo file exists at the correct path
2. Verify the locale code matches your folder name (e.g., `hu_HU` not `hu`)
3. Ensure the .mo file was compiled correctly with `msgfmt`

### Check if fallback is being used

```php
echo GettextFallback::isUsingNativeGettext() ? 'Native' : 'Fallback';
echo GettextFallback::isLocaleAvailable('hu_HU') ? 'Available' : 'Not available';
```

### Debug translation loading

```php
// Check bound domains
print_r(GettextFallback::getBoundDomains());

// Check current settings
echo "Locale: " . GettextFallback::getLocale();
echo "Domain: " . GettextFallback::getDomain();
```

## License

MIT License
