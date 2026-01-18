<?php

declare(strict_types=1);

namespace LicenseModule;

/**
 * License status constants
 */
final class LicenseStatus
{
    /** License is valid and active */
    public const ACTIVE = 'active';

    /** License has expired */
    public const EXPIRED = 'expired';

    /** License is invalid (wrong key, domain mismatch, etc.) */
    public const INVALID = 'invalid';

    /** License has been suspended by the license server */
    public const SUSPENDED = 'suspended';

    /** All valid statuses */
    public const ALL_STATUSES = [
        self::ACTIVE,
        self::EXPIRED,
        self::INVALID,
        self::SUSPENDED,
    ];

    /** Statuses that allow read-only access */
    public const READONLY_STATUSES = [
        self::EXPIRED,
    ];

    /** Statuses that block all access */
    public const BLOCKED_STATUSES = [
        self::INVALID,
        self::SUSPENDED,
    ];

    /**
     * Map server status to module status
     *
     * @param string $serverStatus Status from license server
     * @param bool $isValid Whether the license is valid
     * @return string Mapped status
     */
    public static function mapFromServer(string $serverStatus, bool $isValid): string
    {
        return match (strtolower($serverStatus)) {
            'active', 'valid' => self::ACTIVE,
            'inactive' => self::EXPIRED,
            'revoked' => self::SUSPENDED,
            'expired' => self::EXPIRED,
            default => $isValid ? self::ACTIVE : self::INVALID,
        };
    }

    /**
     * Check if status allows normal operation
     *
     * @param string $status License status
     * @return bool
     */
    public static function isActive(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    /**
     * Check if status is read-only mode
     *
     * @param string $status License status
     * @return bool
     */
    public static function isReadOnly(string $status): bool
    {
        return in_array($status, self::READONLY_STATUSES, true);
    }

    /**
     * Check if status blocks all access
     *
     * @param string $status License status
     * @return bool
     */
    public static function isBlocked(string $status): bool
    {
        return in_array($status, self::BLOCKED_STATUSES, true);
    }
}
