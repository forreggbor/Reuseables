<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Authentication adapter contract for the ActivityLogs admin interface.
 */

declare(strict_types=1);

namespace ActivityLogs\Contracts;

/**
 * AuthAdapterInterface - host-side authentication bridge for the admin UI
 *
 * Implemented by the host project to gate access to the activity log viewer
 * and to supply user information for the user-filter dropdown and display names.
 *
 * @package ActivityLogs
 */
interface AuthAdapterInterface
{
    /**
     * Determine whether the current request is authorized to view the activity log.
     *
     * @return bool True if the viewer should be shown
     */
    public function isAuthorized(): bool;

    /**
     * Return the ID of the currently authenticated user, or null for anonymous/system.
     *
     * @return int|null Current user ID
     */
    public function getCurrentUserId(): ?int;

    /**
     * Return a display-name map for the given user IDs.
     *
     * Called with the distinct user_id values found in the current result page so
     * the implementation can resolve names in one query instead of N queries.
     *
     * @param array<int> $ids User IDs to resolve (may be empty)
     * @return array<int,string> Map of id → display name for every requested id
     */
    public function getUserMap(array $ids): array;
}
