<?php

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PatchModule central error code constants.
 */

declare(strict_types=1);

namespace PatchModule;

/**
 * ErrorCode - Central error code constants for PatchModule
 *
 * All error codes that appear in result arrays from this module are defined here.
 * Callers match on these constants rather than parsing error strings.
 *
 * @package PatchModule
 */
class ErrorCode
{
    // Archive / manifest validation
    public const INVALID_ARCHIVE         = 'invalid_archive';
    public const INVALID_MANIFEST_PATH   = 'invalid_manifest_path';
    public const INVALID_MANIFEST_SCHEMA = 'invalid_manifest_schema';

    // Preflight / concurrency
    public const INSTALL_IN_PROGRESS     = 'install_in_progress';

    // Download / server errors (from ServerErrorMapper)
    public const NETWORK_ERROR           = 'network_error';
    public const RATE_LIMITED            = 'rate_limited';
    public const SIGNING_UNAVAILABLE     = 'signing_unavailable';
    public const SERVER_ERROR            = 'server_error';
    public const NOT_RECENTLY_VERIFIED   = 'not_recently_verified';
    public const PACKAGE_MISMATCH        = 'package_mismatch';
    public const INVALID_LICENSE         = 'invalid_license';
    public const LICENSE_EXPIRED         = 'license_expired';
    public const LICENSE_IP_MISMATCH     = 'license_ip_mismatch';
    public const LICENSE_REVOKED         = 'license_revoked';

    // Verification
    public const VERIFICATION_FAILED     = 'verification_failed';
}
