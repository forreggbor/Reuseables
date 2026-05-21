<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Exception thrown when the CronAdmin configuration array is invalid.
 */

declare(strict_types=1);

namespace CronAdmin\Exceptions;

/**
 * Thrown by ConfigValidator when a required config key is missing or invalid.
 *
 * The message always names the offending key so the integrator knows exactly
 * what to fix without having to read the source.
 */
class InvalidConfigException extends \InvalidArgumentException
{
    /**
     * @param string          $key      The config key that is missing or invalid.
     * @param string          $reason   Human-readable explanation of the failure.
     * @param int             $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        private readonly string $key,
        string $reason,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            "Invalid CronAdmin config for key '{$key}': {$reason}",
            $code,
            $previous
        );
    }

    /**
     * Returns the config key that triggered this exception.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }
}
