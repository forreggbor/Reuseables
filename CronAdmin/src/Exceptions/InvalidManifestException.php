<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Exception thrown when the cron job manifest file fails validation.
 * Aggregates all violations so callers can report every problem at once.
 */

declare(strict_types=1);

namespace CronAdmin\Exceptions;

/**
 * Thrown by ManifestReader::load() when one or more manifest entries are invalid.
 *
 * Carries a flat list of human-readable violation messages so the caller
 * can surface all problems together rather than fixing them one by one.
 */
class InvalidManifestException extends \RuntimeException
{
    /** @var list<string> */
    private array $violations;

    /**
     * @param list<string> $violations  One message per validation failure.
     * @param int          $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        array $violations,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->violations = $violations;
        parent::__construct(
            'Manifest validation failed: ' . implode('; ', $violations),
            $code,
            $previous
        );
    }

    /**
     * Returns every validation violation found in the manifest.
     *
     * @return list<string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
