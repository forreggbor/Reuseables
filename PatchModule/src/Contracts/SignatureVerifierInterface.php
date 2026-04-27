<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * SignatureVerifierInterface - Contract for patch metadata signature verification
 */

namespace PatchModule\Contracts;

/**
 * Contract for verifying patch metadata signatures received from the patch server.
 *
 * Implementations receive the canonical payload, the server's public key PEM, and
 * the base64url-encoded signature, and must return whether the signature is valid.
 *
 * @package PatchModule\Contracts
 */
interface SignatureVerifierInterface
{
    /**
     * Verify that a signature matches the canonical JSON of the given payload.
     *
     * The payload is encoded to canonical JSON (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
     * before verification, matching the server-side signing process. Returns false on any
     * error — bad public key, bad signature encoding, or a failed cryptographic check.
     *
     * @param array  $payload         Associative array that was signed by the server
     * @param string $publicKeyPem    PEM-encoded public key from the server response
     * @param string $signatureB64Url Base64url-encoded signature from the server response
     * @return bool True only when the signature is cryptographically valid
     */
    public function verify(array $payload, string $publicKeyPem, string $signatureB64Url): bool;
}
