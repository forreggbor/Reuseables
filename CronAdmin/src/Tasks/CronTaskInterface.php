<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Contract for all cron task classes managed by the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Tasks;

/**
 * Every host-defined cron task MUST implement this interface.
 *
 * Task classes MUST have a no-argument constructor. Tasks that need access to
 * database connections or application services should reach into host singletons
 * (e.g. Database::getInstance()) inside their constructor or run() method.
 *
 * The class name is declared in the manifest (cron/jobs.php) under the 'class' key
 * and is instantiated by JobRunner via `new $class()`.
 */
interface CronTaskInterface
{
    /**
     * Executes the task and returns the outcome.
     *
     * Any output echoed during run() is captured by JobRunner via ob_start()
     * and stored as last_output_excerpt (truncated to 8 KB). Tasks MAY echo
     * progress information freely — callers do not see it directly.
     *
     * @return CronTaskResult
     */
    public function run(): CronTaskResult;

    /**
     * Returns the default lock timeout in seconds for this task.
     *
     * Used only when the manifest entry does not specify lock_timeout_seconds.
     * At runtime, JobRunner always reads the persisted value from the DB row,
     * never calls this method on the live task instance.
     *
     * @return int  Positive integer; default implementations return 3600.
     */
    public function lockTimeoutSeconds(): int;
}
