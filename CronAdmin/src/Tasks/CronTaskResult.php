<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Immutable value object representing the outcome of a single cron task execution.
 */

declare(strict_types=1);

namespace CronAdmin\Tasks;

/**
 * Holds the result of a CronTaskInterface::run() invocation.
 *
 * Constructed exclusively via the static factory methods to guarantee
 * valid status values. Output captured from the task (stdout/stderr via
 * ob_start) is stored separately in $outputExcerpt by JobRunner —
 * tasks themselves do not populate it.
 */
readonly class CronTaskResult
{
    /** @var string Status constant: execution completed without error. */
    public const STATUS_SUCCESS = 'success';

    /** @var string Status constant: execution failed with an error. */
    public const STATUS_FAILURE = 'failure';

    /** @var string Status constant: execution was intentionally skipped. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param string      $status        One of STATUS_* constants.
     * @param string      $message       Human-readable summary for logs/email.
     * @param int         $durationMs    Elapsed milliseconds (0 when unknown).
     * @param string|null $errorMessage  Error or skip-reason sentinel; populated for STATUS_FAILURE and STATUS_SKIPPED.
     */
    public function __construct(
        public string $status,
        public string $message,
        public int $durationMs = 0,
        public ?string $errorMessage = null,
    ) {}

    /**
     * Creates a success result.
     *
     * @param string $message
     * @param int    $durationMs
     * @return self
     */
    public static function success(string $message = '', int $durationMs = 0): self
    {
        return new self(self::STATUS_SUCCESS, $message, $durationMs);
    }

    /**
     * Creates a failure result.
     *
     * @param string $message       Summary message.
     * @param string $errorMessage  Detailed error description.
     * @param int    $durationMs
     * @return self
     */
    public static function failure(string $message = '', string $errorMessage = '', int $durationMs = 0): self
    {
        return new self(self::STATUS_FAILURE, $message, $durationMs, $errorMessage ?: null);
    }

    /**
     * Creates a skipped result.
     *
     * @param string $message  Reason the task was skipped.
     * @return self
     */
    public static function skipped(string $message = ''): self
    {
        return new self(self::STATUS_SKIPPED, $message, 0, $message ?: null);
    }

    /**
     * Returns true when this result represents a successful execution.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Returns true when this result represents a failed execution.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILURE;
    }

    /**
     * Returns true when this result represents a skipped execution.
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }
}
