<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * CronAdmin — framework-agnostic reusable cron job management module.
 */

declare(strict_types=1);

namespace CronAdmin;

use CronAdmin\Contracts\LoggerInterface;
use CronAdmin\Exceptions\InvalidConfigException;

/**
 * Entry point for the CronAdmin module.
 *
 * Accepts a configuration array, validates it, and lazily constructs the
 * internal dependency graph. Hosts interact with the module exclusively
 * through this facade.
 *
 * Usage:
 *   $cron = new CronAdmin\CronAdmin([...config...]);
 *   $cron->dispatch();                // cron/run.php
 *   $cron->getAdminActions()->index(); // admin route handler
 */
class CronAdmin
{
    public const VERSION = '1.3.1';

    /** @var array<string, mixed> Normalised, validated config. */
    private array $config;

    private TimeZoneHelper $tz;

    private ?Dispatcher   $dispatcher   = null;
    private ?AdminActions $adminActions = null;
    private ?LoggerInterface $logger    = null;

    /**
     * @param array<string, mixed> $config  See README.md § Configuration for the full key table.
     * @throws InvalidConfigException  When a required config key is missing or invalid.
     */
    public function __construct(array $config)
    {
        $this->config = (new ConfigValidator())->validate($config);
        $this->tz     = new TimeZoneHelper($this->config['display_timezone']);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Dispatches all due cron jobs for the current minute.
     *
     * Call from cron/run.php via `* * * * *` crontab entry.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $this->getDispatcher()->dispatch();
    }

    /**
     * Runs a single job unconditionally by job_key.
     *
     * Used by legacy per-job shim scripts (cron/backup.php, etc.). Bypasses
     * the dispatcher kill switch — the operator's explicit invocation is the
     * authorisation.
     *
     * @param string $jobKey  Must match a key declared in the manifest.
     * @return Tasks\CronTaskResult
     */
    public function runByKey(string $jobKey): Tasks\CronTaskResult
    {
        return $this->getDispatcher()->runByKey($jobKey);
    }

    /**
     * Returns the AdminActions instance, or null when admin UI is not fully configured.
     *
     * Check isAvailable() first to distinguish "not configured" from "configured but broken".
     *
     * @return AdminActions|null
     */
    public function getAdminActions(): ?AdminActions
    {
        if (!(new ConfigValidator())->isAdminAvailable($this->config)) {
            return null;
        }

        if ($this->adminActions === null) {
            $db      = $this->config['database'];
            $logger  = $this->getLogger();
            $reader  = new ManifestReader();
            $sync        = new ManifestSyncService($db, $logger);

            $this->adminActions = new AdminActions(
                $db,
                $this->config['auth_adapter'],
                $this->config['csrf_adapter'],
                $this->config['dispatcher_kill_switch'],
                $reader,
                $sync,
                $logger,
                $this->config['manifest_path'],
                $this->config['base_url'],
                $this->config['asset_base_url'],
                $this->config['use_bootstrap'],
                $this->tz,
            );
        }

        return $this->adminActions;
    }

    /**
     * Returns availability information for the admin UI.
     *
     * @return array{enabled: bool, reason: string|null}
     */
    public function isAvailable(): array
    {
        $validator = new ConfigValidator();
        if ($validator->isAdminAvailable($this->config)) {
            return ['enabled' => true, 'reason' => null];
        }

        // Identify the first missing admin UI key.
        $missing = [];
        foreach (['auth_adapter', 'csrf_adapter', 'base_url'] as $key) {
            if (empty($this->config[$key])) {
                $missing[] = $key;
            }
        }

        $reason = 'Missing ' . implode(', ', $missing);
        return ['enabled' => false, 'reason' => $reason];
    }

    // =========================================================================
    // Internal factory
    // =========================================================================

    /**
     * Lazily constructs and returns the Dispatcher.
     *
     * @return Dispatcher
     */
    private function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher === null) {
            $db          = $this->config['database'];
            $killSwitch  = $this->config['dispatcher_kill_switch'];
            $logger      = $this->getLogger();
            $lockDir     = $this->config['lock_dir'];
            $lockManager = new LockManager($lockDir, $logger);
            $reader      = new ManifestReader();
            $sync        = new ManifestSyncService($db, $logger);

            $jobRunner = new JobRunner(
                $db,
                $lockManager,
                $logger,
                $this->config['mail_adapter'],
                $this->config['recipient_resolver'],
            );

            $this->dispatcher = new Dispatcher(
                $db,
                $killSwitch,
                $reader,
                $sync,
                new Scheduler($this->tz),
                $jobRunner,
                $lockManager,
                $logger,
                $this->config['manifest_path'],
                $lockDir,
                $this->tz,
            );
        }

        return $this->dispatcher;
    }

    /**
     * Returns the configured logger, or a no-op fallback.
     *
     * @return LoggerInterface
     */
    private function getLogger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = $this->config['logger'] instanceof LoggerInterface
                ? $this->config['logger']
                : new class implements LoggerInterface {
                    public function log(string $message, string $level = self::INFO, array $context = []): void {}
                    public function debug(string $message, array $context = []): void {}
                    public function info(string $message, array $context = []): void {}
                    public function warning(string $message, array $context = []): void {}
                    public function error(string $message, array $context = []): void {}
                };
        }
        return $this->logger;
    }
}
