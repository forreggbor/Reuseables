<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Encryption seam for remote-server credentials (SFTP username/password/private key).
 */

namespace BackupRestore\Contracts;

/**
 * Encrypts and decrypts secrets at rest — used for storing remote-server
 * (SFTP) credentials in the backup_remote_servers table.
 *
 * Hosts with an existing encryption scheme (e.g. JupitERP's App\Helpers\Security,
 * which must keep reading legacy AES-256-CBC blobs alongside new AES-256-GCM
 * ciphertext) should ship an adapter delegating to that scheme rather than
 * re-encrypting existing stored credentials with the module's generic default.
 *
 * @package BackupRestore
 */
interface EncryptorInterface
{
    /**
     * Encrypt a plaintext secret for storage.
     *
     * @param string $plain Plaintext value
     * @return string Ciphertext, safe to store in a TEXT column
     */
    public function encrypt(string $plain): string;

    /**
     * Decrypt a ciphertext produced by encrypt().
     *
     * Never throws — implementations return null on any failure (wrong key,
     * corrupted data, unrecognized format) so callers can treat decrypt
     * failure as a normal, recoverable error condition.
     *
     * @param string $cipher Ciphertext previously produced by encrypt()
     * @return string|null Decrypted plaintext, or null if decryption failed
     */
    public function decrypt(string $cipher): ?string;
}
