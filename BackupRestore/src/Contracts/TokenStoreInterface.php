<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Short-lived, single-use token storage — abstracts the download-token
 * bucket that used to live directly in $_SESSION inside the ported engine.
 */

namespace BackupRestore\Contracts;

/**
 * Stores short-lived, single-use tokens with an associated payload.
 *
 * Used by BackupEngine::generateDownloadToken()/consumeDownloadToken() so the
 * engine never touches $_SESSION directly. The shipped ArrayTokenStore is an
 * in-memory implementation suitable for CLI/tests; a web host should provide
 * a session-backed implementation.
 *
 * @package BackupRestore
 */
interface TokenStoreInterface
{
    /**
     * Store a token with its payload and expiry.
     *
     * @param string $token   Opaque token identifier
     * @param array  $payload Data to associate with the token (e.g. ['backup_id' => 5])
     * @param int    $ttlSeconds Seconds until the token expires
     * @return void
     */
    public function put(string $token, array $payload, int $ttlSeconds): void;

    /**
     * Retrieve and immediately invalidate a token (single-use).
     *
     * Returns null if the token does not exist or has expired; a token that
     * has already been taken once must not be returned again.
     *
     * @param string $token Opaque token identifier
     * @return array|null The stored payload, or null if invalid/expired/already consumed
     */
    public function take(string $token): ?array;
}
