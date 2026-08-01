<?php

/**
 * RequiredValidator — Validates required environment variables after .env loading.
 *
 * Used via chaining (required() is on the DotEnv instance, not on load()'s array result):
 *   $dotenv = DotEnv::createImmutable('/path');
 *   $dotenv->load();
 *   $dotenv->required(['DB_HOST', 'DB_NAME'])->notEmpty();
 *
 * @package   DotEnv
 * @version   1.0.0
 */

declare(strict_types=1);

namespace DotEnv;

/**
 * Validates that required environment variable keys are present and non-empty in $_ENV.
 */
class RequiredValidator
{
    /** @var array<int, string> Keys that must be present in $_ENV. */
    private array $keys;

    /**
     * @param array<int, string> $keys Environment variable names to validate.
     */
    public function __construct(array $keys)
    {
        $this->keys = $keys;
    }

    /**
     * Assert that all required keys exist in $_ENV and are non-empty strings.
     *
     * @throws \RuntimeException If any required key is missing or empty.
     *
     * @return void
     */
    public function notEmpty(): void
    {
        $missing = [];
        $empty   = [];

        foreach ($this->keys as $key) {
            if (!isset($_ENV[$key])) {
                $missing[] = $key;
            } elseif (trim((string) $_ENV[$key]) === '') {
                $empty[] = $key;
            }
        }

        $errors = [];

        if (!empty($missing)) {
            $errors[] = 'Missing required environment variables: ' . implode(', ', $missing);
        }

        if (!empty($empty)) {
            $errors[] = 'The following required environment variables are empty: ' . implode(', ', $empty);
        }

        if (!empty($errors)) {
            throw new \RuntimeException('DotEnv: ' . implode(' | ', $errors) . '.');
        }
    }
}
