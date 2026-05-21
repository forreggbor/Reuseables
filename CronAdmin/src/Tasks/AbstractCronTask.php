<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Base class providing default implementations for CronTaskInterface.
 */

declare(strict_types=1);

namespace CronAdmin\Tasks;

/**
 * Convenient base for all cron task implementations.
 *
 * Provides factory shorthand methods (success, failure, skipped) so task
 * implementations can return results without importing CronTaskResult directly,
 * and supplies the standard 3600-second lock timeout default.
 */
abstract class AbstractCronTask implements CronTaskInterface
{
    /**
     * {@inheritdoc}
     */
    public function lockTimeoutSeconds(): int
    {
        return 3600;
    }

    /**
     * Creates a success result. Convenience wrapper around CronTaskResult::success().
     *
     * @param string $message
     * @param int    $durationMs
     * @return CronTaskResult
     */
    protected function success(string $message = '', int $durationMs = 0): CronTaskResult
    {
        return CronTaskResult::success($message, $durationMs);
    }

    /**
     * Creates a failure result. Convenience wrapper around CronTaskResult::failure().
     *
     * @param string $message
     * @param string $errorMessage
     * @param int    $durationMs
     * @return CronTaskResult
     */
    protected function failure(string $message = '', string $errorMessage = '', int $durationMs = 0): CronTaskResult
    {
        return CronTaskResult::failure($message, $errorMessage, $durationMs);
    }

    /**
     * Creates a skipped result. Convenience wrapper around CronTaskResult::skipped().
     *
     * @param string $message
     * @return CronTaskResult
     */
    protected function skipped(string $message = ''): CronTaskResult
    {
        return CronTaskResult::skipped($message);
    }
}
