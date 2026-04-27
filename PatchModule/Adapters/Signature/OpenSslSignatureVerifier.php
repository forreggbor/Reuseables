<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * OpenSslSignatureVerifier - Verifies patch metadata signatures using OpenSSL
 */

namespace PatchModule\Adapters\Signature;

use PatchModule\Contracts\SignatureVerifierInterface;

/**
 * Verifies patch metadata signatures using PHP's OpenSSL extension.
 *
 * Decodes the base64url-encoded signature, rebuilds the canonical JSON payload
 * using the same flags the server uses (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
 * and calls openssl_verify with OPENSSL_ALGO_SHA256. Any error — invalid PEM, bad
 * base64, or a failed verification — returns false without throwing.
 *
 * @package PatchModule\Adapters\Signature
 */
class OpenSslSignatureVerifier implements SignatureVerifierInterface
{
    /**
     * Verify a patch metadata signature using OpenSSL.
     *
     * Returns false on any error condition: undecodable public key, malformed
     * base64url signature, or a negative/zero result from openssl_verify.
     *
     * @param array  $payload         Associative array that was signed by the server
     * @param string $publicKeyPem    PEM-encoded public key from the server response
     * @param string $signatureB64Url Base64url-encoded signature from the server response
     * @return bool True only when openssl_verify returns 1 (valid signature)
     */
    public function verify(array $payload, string $publicKeyPem, string $signatureB64Url): bool
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        // Reverse base64url encoding: restore padding and swap back the URL-safe chars
        $base64 = strtr($signatureB64Url, '-_', '+/');
        $padded = str_pad($base64, (int) (ceil(strlen($base64) / 4) * 4), '=');
        $signature = base64_decode($padded, true);
        if ($signature === false) {
            return false;
        }

        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return false;
        }

        // openssl_verify returns 1 (valid), 0 (invalid), or -1 (error)
        $result = openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }
}
