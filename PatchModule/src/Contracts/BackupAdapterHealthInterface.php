<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Optional health probe of a backup adapter.
 */

namespace PatchModule\Contracts;

/**
 * Lets a backup adapter say whether it can actually make backups right now.
 *
 * Optional companion of BackupAdapterInterface (kept separate so existing custom adapters keep working
 * unchanged): an adapter that does not implement it is assumed to work. The install modal only offers the
 * backup option when the adapter exists and reports itself operational.
 *
 * @package PatchModule
 */
interface BackupAdapterHealthInterface
{
    /**
     * Check, without creating a backup, that the adapter can work (binaries, extensions, backup directory,
     * bookkeeping table, connection).
     *
     * @return array{ok: bool, reason: ?string} "reason" explains a failure (English, for logs and diagnostics).
     */
    public function checkOperational(): array;
}
