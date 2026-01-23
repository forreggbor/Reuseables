<?php

declare(strict_types=1);

namespace GettextFallback;

/**
 * GettextFallback - Gettext translation with fallback for missing locales
 *
 * Provides gettext-compatible translation functionality that works even when
 * system locales are not installed (common on shared hosting). Automatically
 * detects locale availability and falls back to a custom MO file parser.
 *
 * Features:
 * - Automatic locale availability detection
 * - Custom binary MO file parser
 * - Transparent fallback (uses native gettext when available)
 * - Full plural form support with expression evaluation
 * - Multiple text domains
 * - Context-aware translations (pgettext)
 * - In-memory translation caching
 *
 * @package GettextFallback
 * @version 1.0.0
 * @license MIT
 */
class GettextFallback
{
    /**
     * Configuration options
     */
    private static array $config = [
        'locale_path' => '/locale',
        'default_domain' => 'messages',
        'default_locale' => 'en_US',
        'use_native_if_available' => true,
        'cache_translations' => true,
    ];

    /**
     * Whether the component has been initialized
     */
    private static bool $initialized = false;

    /**
     * Whether native gettext is available for current locale
     */
    private static bool $nativeAvailable = false;

    /**
     * Current locale code
     */
    private static string $currentLocale = 'en_US';

    /**
     * Current text domain
     */
    private static string $currentDomain = 'messages';

    /**
     * Bound domains with their paths
     * @var array<string, string>
     */
    private static array $boundDomains = [];

    /**
     * Translation cache
     * @var array<string, array{translations: array<string, string|array<int, string>>, plural_rule: ?callable, nplurals: int}>
     */
    private static array $translationCache = [];

    /**
     * Initialize the GettextFallback component
     *
     * @param array{
     *     locale_path?: string,
     *     default_domain?: string,
     *     default_locale?: string,
     *     use_native_if_available?: bool,
     *     cache_translations?: bool
     * } $config Configuration options
     * @return void
     */
    public static function init(array $config = []): void
    {
        self::$config = array_merge(self::$config, $config);
        self::$currentDomain = self::$config['default_domain'];
        self::$currentLocale = self::$config['default_locale'];
        self::$initialized = true;

        // Check native gettext availability for default locale
        self::checkNativeGettext();
    }

    /**
     * Get current configuration
     *
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Set the current locale
     *
     * @param string $locale Locale code (e.g., 'hu_HU', 'en_US')
     * @return bool True if locale was set successfully
     */
    public static function setLocale(string $locale): bool
    {
        self::ensureInitialized();

        self::$currentLocale = $locale;
        self::checkNativeGettext();

        return true;
    }

    /**
     * Get the current locale
     *
     * @return string Current locale code
     */
    public static function getLocale(): string
    {
        return self::$currentLocale;
    }

    /**
     * Set the current text domain
     *
     * @param string $domain Text domain name
     * @return void
     */
    public static function setDomain(string $domain): void
    {
        self::ensureInitialized();

        self::$currentDomain = $domain;

        if (self::$nativeAvailable) {
            textdomain($domain);
        }
    }

    /**
     * Get the current text domain
     *
     * @return string Current domain name
     */
    public static function getDomain(): string
    {
        return self::$currentDomain;
    }

    /**
     * Bind a text domain to a directory path
     *
     * @param string $domain Text domain name
     * @param string $path Directory path containing locale folders
     * @return void
     */
    public static function bindDomain(string $domain, string $path): void
    {
        self::ensureInitialized();

        self::$boundDomains[$domain] = rtrim($path, '/');

        if (self::$nativeAvailable) {
            bindtextdomain($domain, $path);
            bind_textdomain_codeset($domain, 'UTF-8');
        }
    }

    /**
     * Translate a string
     *
     * @param string $msgid Message ID (original string)
     * @return string Translated string or original if not found
     */
    public static function translate(string $msgid): string
    {
        self::ensureInitialized();

        if (self::$nativeAvailable && self::$config['use_native_if_available']) {
            return gettext($msgid);
        }

        return self::lookupTranslation(self::$currentDomain, $msgid);
    }

    /**
     * Translate a string with plural form
     *
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $n Count for determining plural form
     * @return string Translated string
     */
    public static function nTranslate(string $singular, string $plural, int $n): string
    {
        self::ensureInitialized();

        if (self::$nativeAvailable && self::$config['use_native_if_available']) {
            return ngettext($singular, $plural, $n);
        }

        return self::lookupPluralTranslation(self::$currentDomain, $singular, $plural, $n);
    }

    /**
     * Translate a string from a specific domain
     *
     * @param string $domain Text domain
     * @param string $msgid Message ID
     * @return string Translated string
     */
    public static function dTranslate(string $domain, string $msgid): string
    {
        self::ensureInitialized();

        if (self::$nativeAvailable && self::$config['use_native_if_available']) {
            return dgettext($domain, $msgid);
        }

        return self::lookupTranslation($domain, $msgid);
    }

    /**
     * Translate a plural string from a specific domain
     *
     * @param string $domain Text domain
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $n Count
     * @return string Translated string
     */
    public static function dnTranslate(string $domain, string $singular, string $plural, int $n): string
    {
        self::ensureInitialized();

        if (self::$nativeAvailable && self::$config['use_native_if_available']) {
            return dngettext($domain, $singular, $plural, $n);
        }

        return self::lookupPluralTranslation($domain, $singular, $plural, $n);
    }

    /**
     * Translate a string with context
     *
     * @param string $context Context for disambiguation
     * @param string $msgid Message ID
     * @return string Translated string
     */
    public static function pTranslate(string $context, string $msgid): string
    {
        self::ensureInitialized();

        // Native gettext doesn't have pgettext, we need to use the context\x04msgid format
        $contextMsgid = $context . "\x04" . $msgid;

        if (self::$nativeAvailable && self::$config['use_native_if_available']) {
            $translation = gettext($contextMsgid);
            // If translation equals the context+msgid, it wasn't found
            return $translation === $contextMsgid ? $msgid : $translation;
        }

        return self::lookupTranslation(self::$currentDomain, $contextMsgid, $msgid);
    }

    /**
     * Translate a plural string with context
     *
     * @param string $context Context for disambiguation
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $n Count
     * @return string Translated string
     */
    public static function npTranslate(string $context, string $singular, string $plural, int $n): string
    {
        self::ensureInitialized();

        $contextSingular = $context . "\x04" . $singular;

        if (self::$nativeAvailable && self::$config['use_native_if_available']) {
            $translation = ngettext($contextSingular, $plural, $n);
            if ($translation === $contextSingular) {
                return $n === 1 ? $singular : $plural;
            }
            return $translation;
        }

        return self::lookupPluralTranslation(self::$currentDomain, $contextSingular, $plural, $n, $singular);
    }

    /**
     * Check if native gettext is being used
     *
     * @return bool True if using native gettext
     */
    public static function isUsingNativeGettext(): bool
    {
        return self::$nativeAvailable && self::$config['use_native_if_available'];
    }

    /**
     * Check if a locale is available on the system
     *
     * @param string $locale Locale code to check
     * @return bool True if locale is available
     */
    public static function isLocaleAvailable(string $locale): bool
    {
        return self::checkLocaleAvailable($locale);
    }

    /**
     * Clear the translation cache
     *
     * @param string|null $domain Clear only specific domain (null for all)
     * @return void
     */
    public static function clearCache(?string $domain = null): void
    {
        if ($domain === null) {
            self::$translationCache = [];
        } else {
            foreach (array_keys(self::$translationCache) as $key) {
                if (str_starts_with($key, $domain . '.')) {
                    unset(self::$translationCache[$key]);
                }
            }
        }
    }

    /**
     * Get list of bound domains
     *
     * @return array<string, string> Domain names with their paths
     */
    public static function getBoundDomains(): array
    {
        return self::$boundDomains;
    }

    // -------------------------------------------------------------------------
    // Private Methods
    // -------------------------------------------------------------------------

    /**
     * Ensure the component has been initialized
     *
     * @return void
     */
    private static function ensureInitialized(): void
    {
        if (!self::$initialized) {
            self::init();
        }
    }

    /**
     * Check and set native gettext availability for current locale
     *
     * @return void
     */
    private static function checkNativeGettext(): void
    {
        if (!self::$config['use_native_if_available']) {
            self::$nativeAvailable = false;
            return;
        }

        if (!function_exists('gettext')) {
            self::$nativeAvailable = false;
            return;
        }

        self::$nativeAvailable = self::checkLocaleAvailable(self::$currentLocale);

        if (self::$nativeAvailable) {
            // Set up native gettext
            $variants = self::getLocaleVariants(self::$currentLocale);
            foreach ($variants as $variant) {
                $result = @setlocale(LC_MESSAGES, $variant);
                if ($result !== false) {
                    break;
                }
            }

            // Rebind domains for native gettext
            foreach (self::$boundDomains as $domain => $path) {
                bindtextdomain($domain, $path);
                bind_textdomain_codeset($domain, 'UTF-8');
            }

            textdomain(self::$currentDomain);
        }
    }

    /**
     * Check if a locale is available on the system
     *
     * @param string $locale Locale to check
     * @return bool True if available
     */
    private static function checkLocaleAvailable(string $locale): bool
    {
        $currentLocale = @setlocale(LC_MESSAGES, 0);

        $variants = self::getLocaleVariants($locale);

        foreach ($variants as $variant) {
            $result = @setlocale(LC_MESSAGES, $variant);
            if ($result !== false) {
                // Restore original locale
                @setlocale(LC_MESSAGES, $currentLocale ?: 'C');
                return true;
            }
        }

        // Restore original locale
        @setlocale(LC_MESSAGES, $currentLocale ?: 'C');
        return false;
    }

    /**
     * Get locale variants to try
     *
     * @param string $locale Base locale code
     * @return array<string> Variants to try
     */
    private static function getLocaleVariants(string $locale): array
    {
        return [
            $locale . '.UTF-8',
            $locale . '.utf8',
            $locale . '.UTF8',
            $locale,
            str_replace('_', '-', $locale) . '.UTF-8',
            str_replace('_', '-', $locale),
        ];
    }

    /**
     * Look up a translation from the cache/MO file
     *
     * @param string $domain Text domain
     * @param string $msgid Message ID
     * @param string|null $fallback Fallback if not found (defaults to msgid)
     * @return string Translated string
     */
    private static function lookupTranslation(string $domain, string $msgid, ?string $fallback = null): string
    {
        $data = self::loadTranslations($domain);

        if (isset($data['translations'][$msgid])) {
            $translation = $data['translations'][$msgid];
            // For non-plural entries, return the string directly
            if (is_string($translation)) {
                return $translation;
            }
            // For plural entries stored as array, return first form
            if (is_array($translation) && isset($translation[0])) {
                return $translation[0];
            }
        }

        return $fallback ?? $msgid;
    }

    /**
     * Look up a plural translation
     *
     * @param string $domain Text domain
     * @param string $singular Singular form (or context+singular)
     * @param string $plural Plural form
     * @param int $n Count
     * @param string|null $fallbackSingular Fallback singular (for context translations)
     * @return string Translated string
     */
    private static function lookupPluralTranslation(
        string $domain,
        string $singular,
        string $plural,
        int $n,
        ?string $fallbackSingular = null
    ): string {
        $data = self::loadTranslations($domain);

        if (isset($data['translations'][$singular])) {
            $translation = $data['translations'][$singular];

            if (is_array($translation)) {
                $pluralIndex = self::getPluralIndex($data, $n);
                if (isset($translation[$pluralIndex])) {
                    return $translation[$pluralIndex];
                }
                // Fallback to first available form
                return $translation[0] ?? ($fallbackSingular ?? $singular);
            }

            // Single form stored
            return $translation;
        }

        // Not found - return appropriate fallback
        $fallbackSingularActual = $fallbackSingular ?? $singular;
        return $n === 1 ? $fallbackSingularActual : $plural;
    }

    /**
     * Get plural index for a count
     *
     * @param array $data Translation data with plural_rule
     * @param int $n Count
     * @return int Plural form index
     */
    private static function getPluralIndex(array $data, int $n): int
    {
        if (isset($data['plural_rule']) && is_callable($data['plural_rule'])) {
            $index = ($data['plural_rule'])($n);
            // Ensure index is within bounds
            $nplurals = $data['nplurals'] ?? 2;
            return max(0, min($index, $nplurals - 1));
        }

        // Default: English-style (0 for singular, 1 for plural)
        return $n === 1 ? 0 : 1;
    }

    /**
     * Load translations for a domain
     *
     * @param string $domain Text domain
     * @return array{translations: array, plural_rule: ?callable, nplurals: int}
     */
    private static function loadTranslations(string $domain): array
    {
        $cacheKey = $domain . '.' . self::$currentLocale;

        if (self::$config['cache_translations'] && isset(self::$translationCache[$cacheKey])) {
            return self::$translationCache[$cacheKey];
        }

        $basePath = self::$boundDomains[$domain] ?? self::$config['locale_path'];
        $moPath = $basePath . '/' . self::$currentLocale . '/LC_MESSAGES/' . $domain . '.mo';

        $data = [
            'translations' => [],
            'plural_rule' => null,
            'nplurals' => 2,
        ];

        if (file_exists($moPath) && is_readable($moPath)) {
            $data = self::parseMoFile($moPath);
        }

        if (self::$config['cache_translations']) {
            self::$translationCache[$cacheKey] = $data;
        }

        return $data;
    }

    /**
     * Parse a MO file
     *
     * @param string $path Path to .mo file
     * @return array{translations: array, plural_rule: ?callable, nplurals: int}
     */
    private static function parseMoFile(string $path): array
    {
        $content = file_get_contents($path);

        if ($content === false || strlen($content) < 28) {
            return ['translations' => [], 'plural_rule' => null, 'nplurals' => 2];
        }

        // Read magic number to determine byte order
        $magic = unpack('V', substr($content, 0, 4))[1];

        if ($magic === 0x950412de) {
            $unpackFormat = 'V'; // Little-endian
        } elseif ($magic === 0xde120495) {
            $unpackFormat = 'N'; // Big-endian
        } else {
            return ['translations' => [], 'plural_rule' => null, 'nplurals' => 2];
        }

        // Read header
        $header = unpack(
            "{$unpackFormat}revision/" .
            "{$unpackFormat}count/" .
            "{$unpackFormat}origOffset/" .
            "{$unpackFormat}transOffset/" .
            "{$unpackFormat}hashSize/" .
            "{$unpackFormat}hashOffset",
            substr($content, 4, 24)
        );

        if ($header === false) {
            return ['translations' => [], 'plural_rule' => null, 'nplurals' => 2];
        }

        $translations = [];
        $pluralRule = null;
        $nplurals = 2;

        // Read string pairs
        for ($i = 0; $i < $header['count']; $i++) {
            // Read original string descriptor
            $origDescOffset = $header['origOffset'] + $i * 8;
            $origDesc = unpack(
                "{$unpackFormat}length/{$unpackFormat}offset",
                substr($content, $origDescOffset, 8)
            );

            // Read translation string descriptor
            $transDescOffset = $header['transOffset'] + $i * 8;
            $transDesc = unpack(
                "{$unpackFormat}length/{$unpackFormat}offset",
                substr($content, $transDescOffset, 8)
            );

            if ($origDesc === false || $transDesc === false) {
                continue;
            }

            $original = substr($content, $origDesc['offset'], $origDesc['length']);
            $translation = substr($content, $transDesc['offset'], $transDesc['length']);

            // Empty msgid is the header containing metadata
            if ($original === '') {
                $headerInfo = self::parseHeader($translation);
                $pluralRule = $headerInfo['plural_rule'];
                $nplurals = $headerInfo['nplurals'];
                continue;
            }

            // Handle plural forms (original contains singular\x00plural)
            if (strpos($original, "\x00") !== false) {
                $origParts = explode("\x00", $original);
                $transParts = explode("\x00", $translation);
                // Store with singular as key, translations as array
                $translations[$origParts[0]] = $transParts;
            } else {
                $translations[$original] = $translation;
            }
        }

        return [
            'translations' => $translations,
            'plural_rule' => $pluralRule,
            'nplurals' => $nplurals,
        ];
    }

    /**
     * Parse the MO file header for plural forms info
     *
     * @param string $header Header string
     * @return array{plural_rule: ?callable, nplurals: int}
     */
    private static function parseHeader(string $header): array
    {
        $nplurals = 2;
        $pluralRule = null;

        // Parse Plural-Forms header
        // Format: Plural-Forms: nplurals=N; plural=EXPRESSION;
        if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*([^;]+)/i', $header, $matches)) {
            $nplurals = (int)$matches[1];
            $formula = trim($matches[2]);

            // Remove trailing semicolon if present
            $formula = rtrim($formula, '; ');

            $pluralRule = self::compilePluralRule($formula);
        }

        return [
            'plural_rule' => $pluralRule,
            'nplurals' => $nplurals,
        ];
    }

    /**
     * Compile a plural rule formula into a callable
     *
     * @param string $formula C-style plural expression
     * @return callable Function that takes n and returns plural index
     */
    private static function compilePluralRule(string $formula): callable
    {
        // Common formulas - use optimized versions
        $formula = trim($formula);

        // Hungarian, Turkish, etc.: nplurals=2; plural=(n != 1);
        if ($formula === '(n != 1)' || $formula === 'n != 1' || $formula === 'n!=1') {
            return fn(int $n): int => $n !== 1 ? 1 : 0;
        }

        // English, German, etc.: nplurals=2; plural=(n == 1 ? 0 : 1);
        if ($formula === '(n == 1 ? 0 : 1)' || $formula === 'n == 1 ? 0 : 1') {
            return fn(int $n): int => $n === 1 ? 0 : 1;
        }

        // French, Brazilian Portuguese: nplurals=2; plural=(n > 1);
        if ($formula === '(n > 1)' || $formula === 'n > 1' || $formula === 'n>1') {
            return fn(int $n): int => $n > 1 ? 1 : 0;
        }

        // No plural forms (Chinese, Japanese, Korean, Vietnamese)
        if ($formula === '0') {
            return fn(int $n): int => 0;
        }

        // For complex formulas, use the expression evaluator
        return fn(int $n): int => self::evaluatePluralExpression($formula, $n);
    }

    /**
     * Evaluate a plural expression
     *
     * Safely evaluates C-style ternary and logical expressions.
     * Supported operators: ?: == != > < >= <= % && || ! ( )
     *
     * @param string $expression The expression with 'n' as variable
     * @param int $n The value to substitute for n
     * @return int The plural form index
     */
    private static function evaluatePluralExpression(string $expression, int $n): int
    {
        // Replace 'n' with actual value
        $expr = preg_replace('/\bn\b/', (string)$n, $expression);

        // Tokenize
        $tokens = self::tokenize($expr);

        // Parse and evaluate
        $pos = 0;
        return (int)self::parseExpression($tokens, $pos);
    }

    /**
     * Tokenize an expression
     *
     * @param string $expr Expression string
     * @return array<array{type: string, value: mixed}>
     */
    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $length = strlen($expr);
        $i = 0;

        while ($i < $length) {
            $char = $expr[$i];

            // Skip whitespace
            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // Numbers
            if (ctype_digit($char)) {
                $num = '';
                while ($i < $length && ctype_digit($expr[$i])) {
                    $num .= $expr[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'number', 'value' => (int)$num];
                continue;
            }

            // Two-character operators
            if ($i + 1 < $length) {
                $twoChar = $char . $expr[$i + 1];
                if (in_array($twoChar, ['==', '!=', '>=', '<=', '&&', '||'], true)) {
                    $tokens[] = ['type' => 'operator', 'value' => $twoChar];
                    $i += 2;
                    continue;
                }
            }

            // Single-character operators and punctuation
            if (in_array($char, ['?', ':', '(', ')', '>', '<', '%', '!'], true)) {
                $tokens[] = ['type' => 'operator', 'value' => $char];
                $i++;
                continue;
            }

            // Unknown character - skip
            $i++;
        }

        return $tokens;
    }

    /**
     * Parse ternary expression (lowest precedence)
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseExpression(array $tokens, int &$pos): int
    {
        $condition = self::parseOr($tokens, $pos);

        if (isset($tokens[$pos]) && $tokens[$pos]['value'] === '?') {
            $pos++; // consume '?'
            $thenValue = self::parseExpression($tokens, $pos);

            if (isset($tokens[$pos]) && $tokens[$pos]['value'] === ':') {
                $pos++; // consume ':'
                $elseValue = self::parseExpression($tokens, $pos);
                return $condition ? $thenValue : $elseValue;
            }
        }

        return $condition;
    }

    /**
     * Parse OR expression
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseOr(array $tokens, int &$pos): int
    {
        $left = self::parseAnd($tokens, $pos);

        while (isset($tokens[$pos]) && $tokens[$pos]['value'] === '||') {
            $pos++;
            $right = self::parseAnd($tokens, $pos);
            $left = ($left || $right) ? 1 : 0;
        }

        return $left;
    }

    /**
     * Parse AND expression
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseAnd(array $tokens, int &$pos): int
    {
        $left = self::parseEquality($tokens, $pos);

        while (isset($tokens[$pos]) && $tokens[$pos]['value'] === '&&') {
            $pos++;
            $right = self::parseEquality($tokens, $pos);
            $left = ($left && $right) ? 1 : 0;
        }

        return $left;
    }

    /**
     * Parse equality expression
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseEquality(array $tokens, int &$pos): int
    {
        $left = self::parseComparison($tokens, $pos);

        while (isset($tokens[$pos]) && in_array($tokens[$pos]['value'], ['==', '!='], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = self::parseComparison($tokens, $pos);

            if ($op === '==') {
                $left = ($left == $right) ? 1 : 0;
            } else {
                $left = ($left != $right) ? 1 : 0;
            }
        }

        return $left;
    }

    /**
     * Parse comparison expression
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseComparison(array $tokens, int &$pos): int
    {
        $left = self::parseModulo($tokens, $pos);

        while (isset($tokens[$pos]) && in_array($tokens[$pos]['value'], ['>', '<', '>=', '<='], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = self::parseModulo($tokens, $pos);

            $left = match ($op) {
                '>' => ($left > $right) ? 1 : 0,
                '<' => ($left < $right) ? 1 : 0,
                '>=' => ($left >= $right) ? 1 : 0,
                '<=' => ($left <= $right) ? 1 : 0,
                default => $left,
            };
        }

        return $left;
    }

    /**
     * Parse modulo expression
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseModulo(array $tokens, int &$pos): int
    {
        $left = self::parseUnary($tokens, $pos);

        while (isset($tokens[$pos]) && $tokens[$pos]['value'] === '%') {
            $pos++;
            $right = self::parseUnary($tokens, $pos);
            $left = $right !== 0 ? $left % $right : 0;
        }

        return $left;
    }

    /**
     * Parse unary expression (! operator)
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parseUnary(array $tokens, int &$pos): int
    {
        if (isset($tokens[$pos]) && $tokens[$pos]['value'] === '!') {
            $pos++;
            $value = self::parseUnary($tokens, $pos);
            return $value ? 0 : 1;
        }

        return self::parsePrimary($tokens, $pos);
    }

    /**
     * Parse primary expression (numbers and parentheses)
     *
     * @param array $tokens Token array
     * @param int &$pos Current position
     * @return int Result
     */
    private static function parsePrimary(array $tokens, int &$pos): int
    {
        if (!isset($tokens[$pos])) {
            return 0;
        }

        $token = $tokens[$pos];

        if ($token['type'] === 'number') {
            $pos++;
            return $token['value'];
        }

        if ($token['value'] === '(') {
            $pos++; // consume '('
            $result = self::parseExpression($tokens, $pos);
            if (isset($tokens[$pos]) && $tokens[$pos]['value'] === ')') {
                $pos++; // consume ')'
            }
            return $result;
        }

        return 0;
    }
}
