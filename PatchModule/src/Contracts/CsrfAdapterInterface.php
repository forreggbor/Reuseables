<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Interface for host-side CSRF token generation and validation.
 */

declare(strict_types=1);

namespace PatchModule\Contracts;

/**
 * CSRF adapter for the patch module
 *
 * Delegates CSRF token management to the host application so the module
 * stays framework-agnostic. Tokens are expected to be validated against
 * the X-CSRF-Token request header.
 *
 * @package PatchModule
 */
interface CsrfAdapterInterface
{
    /**
     * Get the current CSRF token to embed in views or API responses
     *
     * @return string The active CSRF token for the current session
     */
    public function getToken(): string;

    /**
     * Validate a CSRF token received in the X-CSRF-Token request header
     *
     * @param string $token The token value to validate
     * @return bool True if the token is valid, false if invalid or missing
     */
    public function validate(string $token): bool;
}
