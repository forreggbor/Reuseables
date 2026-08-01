<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * In-memory implementation of TokenStoreInterface — CLI/harness/test use only.
 */

namespace BackupRestore\Adapters\Token;

use BackupRestore\Contracts\TokenStoreInterface;

/**
 * In-memory, single-request token store.
 *
 * Suitable for CLI scripts, cron, and test harnesses where there is no
 * persistent session. Tokens do not survive past the lifetime of the PHP
 * process holding this instance. A web host needing tokens to survive across
 * separate HTTP requests (e.g. issue on POST /create, redeem on GET /download)
 * must supply a persistent implementation (e.g. session- or DB-backed).
 *
 * @package BackupRestore\Adapters\Token
 */
class ArrayTokenStore implements TokenStoreInterface
{
    /** @var array<string, array{payload: array, expires_at: int}> */
    private array $tokens = [];

    /**
     * @inheritDoc
     */
    public function put(string $token, array $payload, int $ttlSeconds): void
    {
        $this->tokens[$token] = [
            'payload' => $payload,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    /**
     * @inheritDoc
     */
    public function take(string $token): ?array
    {
        if (!isset($this->tokens[$token])) {
            return null;
        }

        $entry = $this->tokens[$token];
        unset($this->tokens[$token]);

        if ($entry['expires_at'] < time()) {
            return null;
        }

        return $entry['payload'];
    }
}
