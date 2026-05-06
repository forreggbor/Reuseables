<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Interface for host-side authentication and install authorization token management.
 */

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Authentication adapter for the patch module
 *
 * Abstracts host-side user authentication and authorization so the module
 * remains framework-agnostic. Covers permission checks, password verification,
 * user identity, and opaque install authorization tokens that replace direct
 * $_SESSION access.
 *
 * @package PatchModule
 */
interface AuthAdapterInterface
{
    /**
     * Check whether the current user is a system administrator
     *
     * @return bool True if the current user has sysadmin privileges
     */
    public function isSysadmin(): bool;

    /**
     * Verify a plaintext password against the current user's stored Argon2id hash
     *
     * @param string $plain Plaintext password to verify
     * @return bool True if the password matches the stored hash
     */
    public function verifyPassword(string $plain): bool;

    /**
     * Get the current authenticated user's ID
     *
     * @return int|null The current user's ID, or null if unauthenticated
     */
    public function getCurrentUserId(): ?int;

    /**
     * Resolve a list of user IDs to their display names
     *
     * Returns a map of user ID to "Lastname Firstname" display name.
     * IDs that cannot be resolved are omitted from the result.
     *
     * @param int[] $ids Array of user IDs to look up
     * @return array<int,string> Map of user ID => display name
     */
    public function getUserMap(array $ids): array;

    /**
     * Issue an opaque token that authorizes a single patch installation
     *
     * The host stores the token in session or cache with the given TTL.
     * The token must be single-use and cryptographically unpredictable.
     *
     * @param int $ttlSeconds Lifetime of the token in seconds
     * @return string The issued authorization token
     */
    public function issueInstallAuthorization(int $ttlSeconds = 1800): string;

    /**
     * Atomically verify and consume an install authorization token
     *
     * The token is invalidated on the first successful call so it cannot
     * be reused. Returns false if the token is expired, not found, or
     * already consumed.
     *
     * @param string $token The token to verify and consume
     * @return bool True if the token was valid and has now been consumed
     */
    public function consumeInstallAuthorization(string $token): bool;
}
