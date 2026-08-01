<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Generic AES-256-GCM implementation of EncryptorInterface.
 */

namespace BackupRestore\Adapters\Crypto;

use BackupRestore\Contracts\EncryptorInterface;

/**
 * Encrypts/decrypts secrets using AES-256-GCM (authenticated encryption).
 *
 * Output format: "v2:" . base64(iv[12] . tag[16] . ciphertext) — chosen to be
 * wire-compatible with JupitERP's App\Helpers\Security::encrypt() v2 format,
 * though this class has no dependency on that project. Hosts that already
 * have an encryption scheme (and must keep reading legacy ciphertext written
 * by it) should ship their own EncryptorInterface adapter delegating to that
 * scheme instead of using this default — see EncryptorInterface docblock.
 *
 * @package BackupRestore\Adapters\Crypto
 */
class OpenSslGcmEncryptor implements EncryptorInterface
{
    private const string CIPHER = 'aes-256-gcm';
    private const string PREFIX = 'v2:';
    private const int IV_LENGTH = 12;
    private const int TAG_LENGTH = 16;

    /**
     * @param string $key Raw or base64-encoded encryption key, at least 32 bytes when decoded
     * @throws \InvalidArgumentException If the key is shorter than 32 bytes
     */
    public function __construct(private readonly string $key)
    {
        // Deliberately validates the base64-decoded length but then uses the
        // ORIGINAL $this->key (not the decoded bytes) in encrypt()/decrypt()
        // below — this looks like a bug but is a required, intentional match
        // to JupitERP's real App\Helpers\Security::getEncryptionKey(), which
        // has the exact same "decode only to check length, pass the original
        // string to openssl_*" shape (app/helpers/Security.php:134-152). Do
        // NOT switch to using the decoded bytes: it would silently break
        // wire-compatibility with every existing "v2:"-prefixed ciphertext
        // written against a real ENCRYPTION_KEY. Confirmed against the actual
        // JupitERP source during the 2026-07-12 security review — the review
        // initially flagged this as inconsistent and briefly "fixed" it
        // before reverting once the host implementation was checked.
        $decoded = base64_decode($this->key, true);
        $keyToCheck = $decoded !== false ? $decoded : $this->key;
        if (strlen($keyToCheck) < 32) {
            throw new \InvalidArgumentException('OpenSslGcmEncryptor requires a key of at least 32 bytes for AES-256');
        }
    }

    /**
     * Encrypt a plaintext secret using AES-256-GCM.
     *
     * @param string $plain Plaintext value
     * @return string "v2:" + base64(iv + tag + ciphertext)
     * @throws \RuntimeException If the underlying OpenSSL call fails
     */
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($ciphertext === false) {
            throw new \RuntimeException('BackupRestore: encryption failed');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a ciphertext produced by encrypt(). Never throws.
     *
     * @param string $cipher Ciphertext previously produced by encrypt()
     * @return string|null Decrypted plaintext, or null on any failure
     */
    public function decrypt(string $cipher): ?string
    {
        if (!str_starts_with($cipher, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        $minLength = self::IV_LENGTH + self::TAG_LENGTH;
        if ($raw === false || strlen($raw) < $minLength) {
            return null;
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, $minLength);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }
}
