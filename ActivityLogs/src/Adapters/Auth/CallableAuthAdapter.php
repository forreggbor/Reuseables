<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Closure-based AuthAdapterInterface implementation for ActivityLogs.
 */

declare(strict_types=1);

namespace ActivityLogs\Adapters\Auth;

use ActivityLogs\Contracts\AuthAdapterInterface;

/**
 * CallableAuthAdapter - wire auth without implementing a class
 *
 * Accepts three callables so a host can bridge its own session/permission layer
 * without creating a dedicated adapter class.
 *
 * Example host wiring:
 *   $auth = new CallableAuthAdapter(
 *       fn() => Auth::isSysadmin(),
 *       fn() => Auth::id(),
 *       fn(array $ids) => User::getNameMap($ids),
 *   );
 *
 * @package ActivityLogs
 */
class CallableAuthAdapter implements AuthAdapterInterface
{
    /**
     * @param callable(): bool                    $isAuthorized    Returns true if the viewer is accessible
     * @param callable(): int|null                $getCurrentUserId Returns the current user ID or null
     * @param callable(array<int>): array<int,string> $getUserMap  Resolves user IDs to display names
     */
    public function __construct(
        private readonly mixed $isAuthorized,
        private readonly mixed $getCurrentUserId,
        private readonly mixed $getUserMap,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isAuthorized(): bool
    {
        return (bool)($this->isAuthorized)();
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentUserId(): ?int
    {
        $id = ($this->getCurrentUserId)();
        return $id !== null ? (int)$id : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $result = ($this->getUserMap)($ids);
        return is_array($result) ? $result : [];
    }
}
