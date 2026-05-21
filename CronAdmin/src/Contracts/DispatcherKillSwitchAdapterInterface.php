<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Dispatcher kill-switch adapter contract for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Contracts;

/**
 * Reads and writes the master dispatcher enabled/disabled flag.
 *
 * Used by Dispatcher::dispatch() on every tick (to gate job execution) and by
 * AdminActions::toggleDispatcher() (to flip the flag from the admin UI).
 * This adapter is REQUIRED — ConfigValidator throws InvalidConfigException if
 * it is absent from the config array.
 *
 * Typical implementations wrap a database-backed system_settings row.
 */
interface DispatcherKillSwitchAdapterInterface
{
    /**
     * Returns true when the dispatcher is enabled, false when it is disabled.
     *
     * Called once per dispatch() tick; implementations MAY cache with a short TTL.
     *
     * @return bool
     */
    public function get(): bool;

    /**
     * Persists the new enabled/disabled state.
     *
     * Called by AdminActions::toggleDispatcher() after the CSRF and auth checks pass.
     *
     * @param bool $enabled
     * @return void
     */
    public function set(bool $enabled): void;
}
