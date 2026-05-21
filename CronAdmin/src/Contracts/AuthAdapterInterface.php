<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Authentication/authorisation adapter contract for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Contracts;

/**
 * Provides identity and authorisation information for the current HTTP request.
 *
 * AdminActions calls isAuthorized() as defence-in-depth before every mutation,
 * even when the host's router middleware has already checked access.
 */
interface AuthAdapterInterface
{
    /**
     * Returns the ID of the currently authenticated user, or null for unauthenticated requests.
     *
     * @return int|null
     */
    public function getCurrentUserId(): ?int;

    /**
     * Returns true when the current user is permitted to perform the given action.
     *
     * Typical implementations map $action to a role check (e.g. isSysadmin()).
     * Known action strings used by AdminActions: 'view', 'save', 'toggle',
     * 'run_now', 'toggle_dispatcher'.
     *
     * @param string $action
     * @return bool
     */
    public function isAuthorized(string $action): bool;

    /**
     * Returns a map of user ID to display name for the given IDs.
     *
     * Used by AdminActions::index() to label updated_by and trigger_pending_by.
     * Returning an empty array is valid — the view gracefully falls back to
     * displaying numeric IDs. Implementations MAY cache this call.
     *
     * @param list<int> $ids  User IDs to resolve.
     * @return array<int, string>  Keys are IDs, values are display names.
     */
    public function getUserMap(array $ids): array;
}
