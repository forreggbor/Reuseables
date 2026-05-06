<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * PatchModule patch_history status constants.
 */

declare(strict_types=1);

namespace PatchModule;

/**
 * PatchHistoryStatus - Status value constants for patch_history records
 *
 * All status strings stored in the patch_history.status column are defined
 * here so callers can match on constants rather than bare string literals.
 *
 * @package PatchModule
 */
class PatchHistoryStatus
{
    public const AVAILABLE   = 'available';
    public const DOWNLOADING = 'downloading';
    public const INSTALLING  = 'installing';
    public const COMPLETED   = 'completed';
    public const FAILED      = 'failed';
    public const ROLLED_BACK = 'rolled_back';
}
