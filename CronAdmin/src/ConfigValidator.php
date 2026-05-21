<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Validates the configuration array passed to the CronAdmin facade.
 */

declare(strict_types=1);

namespace CronAdmin;

use CronAdmin\Contracts\AuthAdapterInterface;
use CronAdmin\Contracts\CsrfAdapterInterface;
use CronAdmin\Contracts\DatabaseAdapterInterface;
use CronAdmin\Contracts\DispatcherKillSwitchAdapterInterface;
use CronAdmin\Contracts\LoggerInterface;
use CronAdmin\Contracts\MailAdapterInterface;
use CronAdmin\Contracts\MailRecipientResolverInterface;
use CronAdmin\Exceptions\InvalidConfigException;

/**
 * Validates the array config passed to the CronAdmin constructor.
 *
 * Throws InvalidConfigException (naming the offending key) on the first
 * violation found so the integrator gets a precise error message.
 */
class ConfigValidator
{
    /**
     * Validates the config array and returns a normalised copy with defaults filled in.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>  Normalised config ready for use by the module.
     * @throws InvalidConfigException
     */
    public function validate(array $config): array
    {
        // ── Required ──────────────────────────────────────────────────────────

        if (empty($config['database']) || !($config['database'] instanceof DatabaseAdapterInterface)) {
            throw new InvalidConfigException('database', 'Must be an instance of DatabaseAdapterInterface.');
        }

        if (empty($config['manifest_path']) || !is_string($config['manifest_path'])) {
            throw new InvalidConfigException('manifest_path', 'Must be a non-empty string (absolute path to cron/jobs.php).');
        }
        if (str_contains($config['manifest_path'], '..')) {
            throw new InvalidConfigException('manifest_path', 'Must not contain ".." — use an absolute path without directory traversal.');
        }
        // SECURITY: manifest_path must point to a file in a deploy-user-owned, web-unreachable
        // directory. The module performs require $manifest_path on every dispatch tick and admin
        // request. A writable or web-accessible manifest_path is unconditional RCE.

        if (empty($config['lock_dir']) || !is_string($config['lock_dir'])) {
            throw new InvalidConfigException('lock_dir', 'Must be a non-empty string (absolute path to the lock directory).');
        }
        if (str_contains($config['lock_dir'], '..')) {
            throw new InvalidConfigException('lock_dir', 'Must not contain ".." — use an absolute path without directory traversal.');
        }
        if (is_dir($config['lock_dir']) && !is_writable($config['lock_dir'])) {
            throw new InvalidConfigException('lock_dir', "Directory '{$config['lock_dir']}' exists but is not writable by the current process.");
        }

        if (empty($config['dispatcher_kill_switch']) || !($config['dispatcher_kill_switch'] instanceof DispatcherKillSwitchAdapterInterface)) {
            throw new InvalidConfigException('dispatcher_kill_switch', 'Must be an instance of DispatcherKillSwitchAdapterInterface. This is required for both the CLI dispatcher and the admin UI.');
        }

        // ── Admin UI — required together ──────────────────────────────────────
        // Any one of these being present without the others is an error.

        $hasAuth    = !empty($config['auth_adapter'])  && $config['auth_adapter']  instanceof AuthAdapterInterface;
        $hasCsrf    = !empty($config['csrf_adapter'])  && $config['csrf_adapter']  instanceof CsrfAdapterInterface;
        $hasBaseUrl = !empty($config['base_url'])       && is_string($config['base_url']);

        $adminCount = ($hasAuth ? 1 : 0) + ($hasCsrf ? 1 : 0) + ($hasBaseUrl ? 1 : 0);

        if ($adminCount > 0 && $adminCount < 3) {
            if (!$hasAuth) {
                throw new InvalidConfigException('auth_adapter', 'Must be an instance of AuthAdapterInterface when admin UI keys are provided.');
            }
            if (!$hasCsrf) {
                throw new InvalidConfigException('csrf_adapter', 'Must be an instance of CsrfAdapterInterface when admin UI keys are provided.');
            }
            if (!$hasBaseUrl) {
                throw new InvalidConfigException('base_url', 'Must be a non-empty string when admin UI keys are provided.');
            }
        }

        if ($hasBaseUrl) {
            $this->validateBaseUrl($config['base_url']);
        }

        // ── Optional ──────────────────────────────────────────────────────────

        if (isset($config['asset_base_url']) && !is_string($config['asset_base_url'])) {
            throw new InvalidConfigException('asset_base_url', 'Must be a string when provided.');
        }

        if (isset($config['use_bootstrap']) && !is_bool($config['use_bootstrap'])) {
            throw new InvalidConfigException('use_bootstrap', 'Must be a boolean when provided.');
        }

        if (isset($config['logger']) && !($config['logger'] instanceof LoggerInterface)) {
            throw new InvalidConfigException('logger', 'Must be an instance of LoggerInterface when provided.');
        }

        if (isset($config['mail_adapter']) && !($config['mail_adapter'] instanceof MailAdapterInterface)) {
            throw new InvalidConfigException('mail_adapter', 'Must be an instance of MailAdapterInterface when provided.');
        }

        if (isset($config['recipient_resolver']) && !($config['recipient_resolver'] instanceof MailRecipientResolverInterface)) {
            throw new InvalidConfigException('recipient_resolver', 'Must be an instance of MailRecipientResolverInterface when provided.');
        }

        // ── Defaults ──────────────────────────────────────────────────────────

        return array_merge([
            'asset_base_url' => '/lib/CronAdmin',
            'use_bootstrap'  => false,
            'logger'         => null,
            'mail_adapter'   => null,
            'recipient_resolver' => null,
            'auth_adapter'   => null,
            'csrf_adapter'   => null,
            'base_url'       => null,
        ], $config);
    }

    /**
     * Returns true when all three admin UI adapters are present in the (normalised) config.
     *
     * @param array<string, mixed> $config  Must be a config already passed through validate().
     * @return bool
     */
    public function isAdminAvailable(array $config): bool
    {
        return $config['auth_adapter']  instanceof AuthAdapterInterface
            && $config['csrf_adapter']  instanceof CsrfAdapterInterface
            && is_string($config['base_url'])
            && $config['base_url'] !== '';
    }

    /**
     * Validates the base_url value: same-origin path only, no dangerous characters.
     *
     * @param string $url
     * @throws InvalidConfigException
     */
    private function validateBaseUrl(string $url): void
    {
        // Must start with / (same-origin path, no scheme, no host).
        if (!str_starts_with($url, '/')) {
            throw new InvalidConfigException('base_url', 'Must be a same-origin path starting with /.');
        }

        // No directory traversal.
        if (str_contains($url, '..')) {
            throw new InvalidConfigException('base_url', 'Must not contain "..".');
        }

        // No double slashes (except the leading /).
        if (preg_match('#/{2,}#', $url)) {
            throw new InvalidConfigException('base_url', 'Must not contain consecutive slashes.');
        }

        // No percent-encoding (potential URL confusion).
        if (str_contains($url, '%')) {
            throw new InvalidConfigException('base_url', 'Must not contain percent-encoded characters.');
        }

        // No whitespace.
        if (preg_match('/\s/', $url)) {
            throw new InvalidConfigException('base_url', 'Must not contain whitespace.');
        }
    }
}
