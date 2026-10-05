<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Repository for NAV company credentials stored in nav_companies.
 */

declare(strict_types=1);

namespace NavSync;

use PDO;

/**
 * Manages NAV company credential records.
 *
 * Encrypts nav_sign_key with AES-256-GCM before storing and decrypts on retrieval.
 * The encryption key must be a 64-char hex string set as NAV_SIGN_KEY_SECRET in .env.
 */
class CompanyRepository
{
    public function __construct(
        private readonly PDO    $pdo,
        private readonly string $encryptionKey
    ) {
        if (strlen($this->encryptionKey) !== 64 || !ctype_xdigit($this->encryptionKey)) {
            throw new \InvalidArgumentException('NavSync: NAV_SIGN_KEY_SECRET must be a 64-char hex string.');
        }
    }

    /**
     * Returns all active companies ordered by name.
     *
     * @return array<object> Rows from nav_companies with plain-text sign_key.
     */
    public function findAllActive(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM nav_companies WHERE active = 1 ORDER BY name ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

        foreach ($rows as $row) {
            $row->nav_sign_key = $this->decrypt($row->nav_sign_key);
        }

        return $rows;
    }

    /**
     * Finds a company by its primary key.
     *
     * @param int $id nav_companies.id
     * @return object|null Row with plain-text sign_key, or null.
     */
    public function findById(int $id): ?object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nav_companies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);

        if ($row === false) {
            return null;
        }

        $row->nav_sign_key = $this->decrypt($row->nav_sign_key);
        return $row;
    }

    /**
     * Inserts a new company record.
     *
     * @param string $name        Display name.
     * @param string $taxNumber   First 8 digits of tax number.
     * @param string $navLogin    NAV technical user login.
     * @param string $navPassword Plain text password — will be hashed with SHA-512.
     * @param string $signKey     Plain text signing key — will be AES-256-GCM encrypted.
     * @return int Inserted row ID.
     */
    public function insert(
        string $name,
        string $taxNumber,
        string $navLogin,
        string $navPassword,
        string $signKey
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO nav_companies (name, tax_number, nav_login, nav_password, nav_sign_key)
            VALUES (:name, :tax_number, :nav_login, :nav_password, :nav_sign_key)
        ');
        $stmt->execute([
            'name'        => $name,
            'tax_number'  => $taxNumber,
            'nav_login'   => $navLogin,
            'nav_password' => strtoupper(hash('sha512', $navPassword)),
            'nav_sign_key' => $this->encrypt($signKey),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Updates an existing company's credentials.
     *
     * Pass null for navPassword or signKey to leave the stored value unchanged.
     *
     * @param int         $id          nav_companies.id
     * @param string      $name        Display name.
     * @param string      $taxNumber   First 8 digits of tax number.
     * @param string      $navLogin    NAV technical user login.
     * @param string|null $navPassword New plain text password, or null to keep current.
     * @param string|null $signKey     New plain text signing key, or null to keep current.
     * @return void
     */
    public function update(
        int     $id,
        string  $name,
        string  $taxNumber,
        string  $navLogin,
        ?string $navPassword,
        ?string $signKey
    ): void {
        $sets   = ['name = :name', 'tax_number = :tax_number', 'nav_login = :nav_login'];
        $params = ['id' => $id, 'name' => $name, 'tax_number' => $taxNumber, 'nav_login' => $navLogin];

        if ($navPassword !== null) {
            $sets[]                  = 'nav_password = :nav_password';
            $params['nav_password'] = strtoupper(hash('sha512', $navPassword));
        }

        if ($signKey !== null) {
            $sets[]                  = 'nav_sign_key = :nav_sign_key';
            $params['nav_sign_key'] = $this->encrypt($signKey);
        }

        $sql = 'UPDATE nav_companies SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Advances the sync cursor (last_sync_at) of a company; it never moves backwards.
     *
     * @param int    $id        nav_companies.id
     * @param string $syncedAt  Datetime string, e.g. "2026-06-26 10:00:00".
     * @return void
     */
    public function updateLastSyncAt(int $id, string $syncedAt): void
    {
        $this->pdo->prepare(
            'UPDATE nav_companies SET last_sync_at = :synced_at
             WHERE id = :id AND (last_sync_at IS NULL OR last_sync_at < :synced_at_cmp)'
        )->execute(['synced_at' => $syncedAt, 'synced_at_cmp' => $syncedAt, 'id' => $id]);
    }

    /**
     * Encrypts a plain text string with AES-256-GCM. Public so the host app can store other secrets under the same key.
     *
     * @param string $plainText Value to encrypt.
     * @return string "base64(iv)|base64(tag)|base64(ciphertext)"
     * @throws \RuntimeException If encryption fails.
     */
    public function encrypt(string $plainText): string
    {
        $key = hex2bin($this->encryptionKey);
        $iv  = random_bytes(12);

        $tag        = '';
        $ciphertext = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('NavSync: AES-256-GCM encryption failed.');
        }

        return base64_encode($iv) . '|' . base64_encode($tag) . '|' . base64_encode($ciphertext);
    }

    /**
     * Decrypts an AES-256-GCM encrypted string. An empty stored value means "no key saved" and yields an empty string.
     *
     * @param string $stored "base64(iv)|base64(tag)|base64(ciphertext)", or '' when no key is saved
     * @return string Plain text.
     * @throws \RuntimeException If decryption fails or the format is invalid.
     */
    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        $parts = explode('|', $stored);
        if (count($parts) !== 3) {
            throw new \RuntimeException('NavSync: Invalid encrypted sign key format.');
        }

        $key        = hex2bin($this->encryptionKey);
        $iv         = base64_decode($parts[0]);
        $tag        = base64_decode($parts[1]);
        $ciphertext = base64_decode($parts[2]);

        $plainText = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plainText === false) {
            throw new \RuntimeException('NavSync: AES-256-GCM decryption failed — wrong key or corrupted data.');
        }

        return $plainText;
    }
}
