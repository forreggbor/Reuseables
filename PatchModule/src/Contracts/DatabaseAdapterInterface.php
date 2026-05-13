<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * DatabaseAdapterInterface - Contract for patch management database operations
 */

namespace PatchModule\Contracts;

/**
 * Database adapter for patch management
 *
 * Provides two categories of operations:
 * 1. Patch history record CRUD (patch_history table)
 * 2. Key-value settings cache (for patch availability caching)
 *
 * @package PatchModule
 */
interface DatabaseAdapterInterface
{
    // =========================================================================
    // Patch History
    // =========================================================================

    /**
     * Get a patch history record by ID
     *
     * @param int $id Record ID
     * @return array|null Associative array of all columns, or null if not found
     */
    public function getHistoryRecord(int $id): ?array;

    /**
     * Find a patch history record by version and status filter
     *
     * @param string $version Semver version string
     * @param array $statuses Allowed status values (e.g., ['available', 'downloading'])
     * @return array|null Associative array or null if not found
     */
    public function findHistoryByVersion(string $version, array $statuses = ['available', 'downloading']): ?array;

    /**
     * Get all completed version strings
     *
     * @return string[] List of version strings with status='completed'
     */
    public function getCompletedVersions(): array;

    /**
     * Get full patch history ordered by created_at DESC
     *
     * May include installer user info if the host application supports it.
     *
     * @return array[] List of associative arrays
     */
    public function getHistory(): array;

    /**
     * Get all manually uploaded patches available for installation
     *
     * Returns patch_history rows where patch_server_id IS NULL and status is
     * 'available', ordered by version ascending. Used to merge uploaded patches
     * into the available-patches list alongside remote-fetched patches.
     *
     * @return array<array<string,mixed>> Rows ordered by version ASC
     */
    public function findUploadedAvailablePatches(): array;

    /**
     * Get version strings of all server-fetched patches currently marked available
     *
     * Returns versions where patch_server_id IS NOT NULL and status is 'available'.
     * Used to compute the diff between what the server last returned and what is still
     * in the local database, so that patches yanked from the server can be marked obsolete.
     *
     * @return string[] Version strings
     */
    public function findAvailableServerVersions(): array;

    /**
     * Mark server-fetched available patches as obsolete
     *
     * Sets status='obsolete' for rows whose version is in $versions and whose current
     * status is 'available' or 'downloading'. Only affects server-fetched rows
     * (patch_server_id IS NOT NULL); manually uploaded rows are never made obsolete this way.
     *
     * @param string[] $versions Version strings to mark obsolete
     * @return int Number of rows updated
     */
    public function markObsoleteByVersions(array $versions): int;

    /**
     * Create a new patch history record
     *
     * @param array $data Column values: version, status, release_notes, file_size,
     *                     sha256_hash, patch_server_id, released_at
     * @return int Inserted record ID
     */
    public function createHistoryRecord(array $data): int;

    /**
     * Update a patch history record
     *
     * @param int $id Record ID
     * @param array $data Column => value pairs to update
     * @return bool True on success
     */
    public function updateHistoryRecord(int $id, array $data): bool;

    // =========================================================================
    // Settings Cache
    // =========================================================================

    /**
     * Get a cached setting value
     *
     * Used keys: patch_last_check_at, patch_available_data, patch_dismissed_versions
     *
     * @param string $key Setting key
     * @return string|null Stored value or null if not set
     */
    public function getSetting(string $key): ?string;

    /**
     * Set a cached setting value
     *
     * @param string $key Setting key
     * @param string|null $value Value to store (null to clear)
     * @return bool True on success
     */
    public function setSetting(string $key, ?string $value): bool;

    // =========================================================================
    // Raw PDO Access
    // =========================================================================

    /**
     * Get the raw PDO connection for SQL migration execution
     *
     * Migrations need direct PDO access to run arbitrary SQL statements
     * with FK check toggling. This is the only method that exposes PDO directly.
     *
     * @return \PDO
     */
    public function getPdo(): \PDO;
}