<?php

/**
 * DotEnv — Lightweight, framework-agnostic .env file parser.
 *
 * Parses KEY=value pairs from a .env file and populates $_ENV.
 * Immutable mode: existing $_ENV values are never overwritten.
 *
 * API mirrors vlucas/phpdotenv for easy migration:
 *   DotEnv::createImmutable('/path/to/dir')->load();
 *   DotEnv::createImmutable('/path/to/dir')->safeLoad();
 *   DotEnv::createImmutable('/path/to/dir')->required(['KEY'])->notEmpty();
 *
 * @package   DotEnv
 * @version   1.0.1
 */

declare(strict_types=1);

namespace DotEnv;

/**
 * Parses .env files and populates the $_ENV superglobal.
 */
class DotEnv
{
    /** @var string Absolute path to the .env file. */
    private string $filePath;

    /** @var bool When true, existing $_ENV keys are not overwritten. */
    private bool $immutable;

    /**
     * Private constructor — use createImmutable() factory method.
     *
     * @param string $filePath  Absolute path to the .env file.
     * @param bool   $immutable Whether to skip keys already in $_ENV.
     */
    private function __construct(string $filePath, bool $immutable = true)
    {
        $this->filePath  = $filePath;
        $this->immutable = $immutable;
    }

    /**
     * Create an immutable loader: existing $_ENV keys will not be overwritten.
     *
     * @param string $directory Path to the directory containing the .env file.
     * @param string $filename  Name of the env file (default: '.env').
     *
     * @return self
     */
    public static function createImmutable(string $directory, string $filename = '.env'): self
    {
        return new self(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename, true);
    }

    /**
     * Load the .env file. Throws RuntimeException if the file does not exist.
     *
     * @return array<string, string> Parsed key-value pairs.
     *
     * @throws \RuntimeException If the .env file cannot be found or read.
     */
    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            throw new \RuntimeException(
                "DotEnv: .env file not found at [{$this->filePath}]."
            );
        }

        if (!is_readable($this->filePath)) {
            throw new \RuntimeException(
                "DotEnv: .env file is not readable at [{$this->filePath}]."
            );
        }

        return $this->parse();
    }

    /**
     * Load the .env file silently. Returns empty array if the file does not exist.
     *
     * @return array<string, string> Parsed key-value pairs, or empty array.
     */
    public function safeLoad(): array
    {
        if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
            return [];
        }

        return $this->parse();
    }

    /**
     * Return a RequiredValidator for the given keys.
     * Validates that each key exists in $_ENV and is non-empty after loading.
     *
     * @param array<int, string> $keys Environment variable names to validate.
     *
     * @return RequiredValidator
     */
    public function required(array $keys): RequiredValidator
    {
        return new RequiredValidator($keys);
    }

    /**
     * Parse the .env file and populate $_ENV.
     *
     * Parsing rules:
     *   - Empty lines and lines starting with # are skipped.
     *   - Lines starting with 'export ' have that prefix stripped.
     *   - Key and value are split on the first '=' only.
     *   - Keys are trimmed; only alphanumeric and underscore characters are valid.
     *   - Values are trimmed; matching outer quotes ("..." or '...') are removed.
     *   - Inline comments (# text) are stripped from unquoted values.
     *   - No variable interpolation (${VAR}) is performed.
     *   - In immutable mode, keys already present in $_ENV are skipped.
     *
     * @return array<string, string> All parsed key-value pairs.
     */
    private function parse(): array
    {
        $lines  = file($this->filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $parsed = [];

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip optional 'export ' prefix.
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            // Must contain '=' to be a valid assignment.
            if (!str_contains($line, '=')) {
                continue;
            }

            // Split on the first '=' only — values may contain '='.
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Skip invalid keys (must be alphanumeric + underscore).
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $value = $this->processValue($value);

            // In immutable mode, do not overwrite existing keys.
            if ($this->immutable && isset($_ENV[$key])) {
                $parsed[$key] = $_ENV[$key];
                continue;
            }

            $_ENV[$key] = $value;
            $parsed[$key] = $value;
        }

        return $parsed;
    }

    /**
     * Process a raw value string: strip outer quotes or strip inline comments.
     *
     * @param string $value Raw value string from the .env line.
     *
     * @return string Processed value.
     */
    private function processValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $firstChar = $value[0];

        // Handle double-quoted values: strip quotes, unescape \n \t \\.
        if ($firstChar === '"') {
            $end = strrpos($value, '"', 1);
            if ($end !== false && $end > 0) {
                $value = substr($value, 1, $end - 1);
                $value = str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $value);
            }
            return $value;
        }

        // Handle single-quoted values: strip quotes, no escape processing.
        if ($firstChar === "'") {
            $end = strrpos($value, "'", 1);
            if ($end !== false && $end > 0) {
                return substr($value, 1, $end - 1);
            }
            return $value;
        }

        // Unquoted value: strip inline comment (# not preceded by backslash).
        $commentPos = $this->findInlineComment($value);
        if ($commentPos !== false) {
            $value = substr($value, 0, $commentPos);
        }

        return trim($value);
    }

    /**
     * Find the position of an inline comment character (#) in an unquoted value.
     * Returns false if no inline comment is found.
     *
     * @param string $value Unquoted value string.
     *
     * @return int|false Position of '#', or false if none found.
     */
    private function findInlineComment(string $value): int|false
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            if ($value[$i] === '#') {
                // Only treat as comment if preceded by whitespace.
                if ($i > 0 && $value[$i - 1] === ' ') {
                    return $i;
                }
            }
        }
        return false;
    }
}
