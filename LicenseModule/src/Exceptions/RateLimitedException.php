<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Exception thrown when the license server returns a 429 Too Many Requests response.
 */

declare(strict_types=1);

namespace LicenseModule\Exceptions;

/**
 * Thrown when the license server rate-limits the validation request
 *
 * This exception signals a temporary condition — the client should back off and
 * retry later. Unlike a network failure, a 429 response means the server is reachable
 * and the license has not changed, so the offline grace period must not be consumed.
 */
class RateLimitedException extends \RuntimeException
{
    /**
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'License server returned 429 Too Many Requests',
        int $code = 429,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
