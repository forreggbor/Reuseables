<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * OpenSslArchiveSignatureVerifier - Verifies detached archive signatures using OpenSSL
 */

namespace PatchModule\Adapters\Signature;

use PatchModule\Contracts\ArchiveSignatureVerifierInterface;

/**
 * Verifies detached RSA-SHA256 signatures on archive files by shelling out to openssl.
 *
 * Writes the public key to a temporary file (mode 0600), invokes `openssl dgst -sha256 -verify`
 * via proc_open using array-form syntax (no shell expansion), and parses the exit code and
 * stdout. The signature is valid only when exit code is 0 and output is exactly "Verified OK".
 *
 * @package PatchModule\Adapters\Signature
 */
class OpenSslArchiveSignatureVerifier implements ArchiveSignatureVerifierInterface
{
    /**
     * Verify a detached signature on an archive file using OpenSSL.
     *
     * Writes $publicKeyPem to a temporary file with mode 0600 (deleted in finally),
     * then shells out to `/usr/bin/openssl dgst -sha256 -verify` using proc_open
     * in array-form (no shell expansion). Returns true only when the exit code is 0
     * and stdout is exactly "Verified OK".
     *
     * @param string $archivePath   Absolute path to the archive file to verify
     * @param string $signaturePath Absolute path to the detached signature file
     * @param string $publicKeyPem  PEM-encoded RSA public key
     * @return bool True only when the signature is cryptographically valid
     * @throws \RuntimeException If the openssl process cannot start or temp file cannot be created
     */
    public function verifyFile(string $archivePath, string $signaturePath, string $publicKeyPem): bool
    {
        $pubKeyTmp = tempnam(sys_get_temp_dir(), 'patch_pub_');
        if ($pubKeyTmp === false) {
            throw new \RuntimeException('Cannot create temp file for public key');
        }

        try {
            // Write public key to temp file with restricted permissions
            file_put_contents($pubKeyTmp, $publicKeyPem);
            chmod($pubKeyTmp, 0600);

            // Build command array (array-form bypasses shell expansion entirely)
            $cmd = [
                '/usr/bin/openssl',
                'dgst',
                '-sha256',
                '-verify',
                $pubKeyTmp,
                '-signature',
                $signaturePath,
                $archivePath,
            ];

            // Open process with stdout and stderr pipes
            $pipes = [];
            $process = proc_open(
                $cmd,
                [
                    1 => ['pipe', 'w'],  // stdout
                    2 => ['pipe', 'w'],  // stderr
                ],
                $pipes
            );

            if ($process === false) {
                throw new \RuntimeException('Failed to start openssl process');
            }

            // Read stdout
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Close stderr (we ignore it)
            fclose($pipes[2]);

            // Get exit code
            $exitCode = proc_close($process);

            // Verification succeeds only when exit code is 0 AND stdout is "Verified OK"
            return $exitCode === 0 && trim($stdout) === 'Verified OK';
        } finally {
            @unlink($pubKeyTmp);
        }
    }
}
