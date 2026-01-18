<?php

declare(strict_types=1);

namespace LicenseModule;

/**
 * Grace period handling for offline mode
 *
 * Manages the grace period during which the system can operate
 * without being able to reach the license server.
 */
class GracePeriodManager
{
    /** Default grace period in days */
    private const DEFAULT_GRACE_PERIOD_DAYS = 7;

    private int $gracePeriodDays;

    /**
     * @param int $gracePeriodDays Grace period in days (default: 7)
     */
    public function __construct(int $gracePeriodDays = self::DEFAULT_GRACE_PERIOD_DAYS)
    {
        $this->gracePeriodDays = $gracePeriodDays;
    }

    /**
     * Get the grace period in days
     *
     * @return int
     */
    public function getGracePeriodDays(): int
    {
        return $this->gracePeriodDays;
    }

    /**
     * Get the grace period in seconds
     *
     * @return int
     */
    public function getGracePeriodSeconds(): int
    {
        return $this->gracePeriodDays * 86400;
    }

    /**
     * Check if the grace period has expired since last validation
     *
     * @param int $lastValidationTimestamp Unix timestamp of last successful validation
     * @return bool True if grace period has expired
     */
    public function isExpired(int $lastValidationTimestamp): bool
    {
        $daysSinceValidation = (time() - $lastValidationTimestamp) / 86400;

        return $daysSinceValidation > $this->gracePeriodDays;
    }

    /**
     * Get remaining days in grace period
     *
     * @param int $lastValidationTimestamp Unix timestamp of last successful validation
     * @return int Days remaining (can be negative if expired)
     */
    public function getRemainingDays(int $lastValidationTimestamp): int
    {
        $daysSinceValidation = (time() - $lastValidationTimestamp) / 86400;

        return (int) ceil($this->gracePeriodDays - $daysSinceValidation);
    }

    /**
     * Check if currently in grace period (not expired but past validation due)
     *
     * @param int $lastValidationTimestamp Unix timestamp of last successful validation
     * @param int $validationFrequencyHours How often validation should occur (default: 24)
     * @return bool True if in grace period
     */
    public function isInGracePeriod(int $lastValidationTimestamp, int $validationFrequencyHours = 24): bool
    {
        $hoursSinceValidation = (time() - $lastValidationTimestamp) / 3600;

        // In grace period if past validation frequency but not expired
        return $hoursSinceValidation > $validationFrequencyHours && !$this->isExpired($lastValidationTimestamp);
    }
}
