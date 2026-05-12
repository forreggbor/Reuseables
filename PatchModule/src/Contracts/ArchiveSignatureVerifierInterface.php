<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * ArchiveSignatureVerifierInterface - Contract for detached-file signature verification
 */

namespace PatchModule\Contracts;

/**
 * Contract for verifying detached signatures on archive files.
 *
 * Implementations receive absolute paths to an archive file (.tgz) and a separate
 * signature file (.sig), along with a PEM-encoded public key. Returns true only if
 * the signature is cryptographically valid for the archive.
 *
 * @package PatchModule\Contracts
 */
interface ArchiveSignatureVerifierInterface
{
    /**
     * Verify that a detached signature is valid for the given archive file.
     *
     * @param string $archivePath    Absolute path to the .tgz archive file
     * @param string $signaturePath  Absolute path to the detached .sig file (raw bytes)
     * @param string $publicKeyPem   PEM-encoded RSA public key
     * @return bool True only when the signature is cryptographically valid
     * @throws \RuntimeException If required dependencies (e.g. openssl binary) are missing
     */
    public function verifyFile(string $archivePath, string $signaturePath, string $publicKeyPem): bool;
}
