<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Optional interface for host-side CSRF token rotation.
 */

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * Optional CSRF token rotation contract for hosts that use per-request token renewal
 *
 * Implement this interface alongside CsrfAdapterInterface when your host generates
 * a fresh CSRF token after every successful mutating action. The module calls rotate()
 * exactly once per successful mutating request (check, dismiss, dismissAll,
 * verifyPassword, install, rollback) and returns the new token to the JS client via
 * the 'csrf_token' field in the JSON response body so it can update its copy before
 * the next request.
 *
 * Contract requirements:
 * - rotate() MUST invalidate the current token, generate and persist a fresh token,
 *   and return the new value.
 * - When this interface is implemented, CsrfAdapterInterface::validate() MUST NOT
 *   rotate the token internally. Rotation is the exclusive responsibility of rotate().
 *   Rotating in both places causes the token to be renewed twice per request, which
 *   leaves the JS client holding a stale token and causes the next call to 403.
 *
 * Hosts that do not rotate tokens (single session-lifetime token) do not need to
 * implement this interface. The module falls back to CsrfAdapterInterface::getToken()
 * and still returns csrf_token in every mutating response — the value is just the
 * same token repeated, which is harmless.
 *
 * @package PatchModule
 */
interface CsrfRotatableInterface
{
    /**
     * Invalidate the current CSRF token, generate and persist a new one, and return it
     *
     * Called by the module after every successful mutating action. The returned value
     * is included in the JSON response body as 'csrf_token' so the JS client updates
     * its internal token before sending the next request.
     *
     * @return string The newly generated and persisted CSRF token
     */
    public function rotate(): string;
}
