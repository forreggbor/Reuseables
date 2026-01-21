<?php

declare(strict_types=1);

namespace MFA;

/**
 * MFAuthenticator - TOTP Multi-Factor Authentication
 *
 * RFC 6238 compliant Time-based One-Time Password implementation.
 * Compatible with Google Authenticator, Authy, Microsoft Authenticator, and other TOTP apps.
 *
 * Security features:
 * - CSPRNG for secret generation (random_bytes)
 * - Timing-safe code verification (hash_equals)
 * - Replay attack prevention support
 * - Argon2id hashed backup codes
 * - Configuration validation
 *
 * @package MFA
 * @version 1.0.0
 * @license MIT
 */
class MFAuthenticator
{
    /**
     * Base32 alphabet for secret encoding
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Default configuration
     */
    private static array $config = [
        'issuer' => 'MyApp',
        'digits' => 6,
        'period' => 30,
        'algorithm' => 'sha1',
        'secret_length' => 20,
        'time_tolerance' => 1,
        'backup_codes_count' => 8,
        'backup_code_length' => 8,
        'qr_size' => 4,
        'qr_margin' => 4,
    ];

    /**
     * Supported algorithms
     */
    private const SUPPORTED_ALGORITHMS = ['sha1', 'sha256', 'sha512'];

    /**
     * Initialize with configuration
     *
     * @param array $config Configuration options:
     *   - issuer: App name shown in authenticator (default: MyApp)
     *   - digits: Code length, 6-8 (default: 6)
     *   - period: Time step in seconds, min 30 (default: 30)
     *   - algorithm: HMAC algorithm (sha1, sha256, sha512) (default: sha1)
     *   - secret_length: Secret key bytes, min 16 (default: 20)
     *   - time_tolerance: Accept codes +/- N periods (default: 1)
     *   - backup_codes_count: Number of backup codes (default: 8)
     *   - backup_code_length: Backup code character length (default: 8)
     *   - qr_size: QR code module size in pixels (default: 4)
     *   - qr_margin: QR code margin in modules (default: 4)
     * @throws \InvalidArgumentException For insecure configuration
     */
    public static function init(array $config = []): void
    {
        self::$config = array_merge(self::$config, $config);
        self::validateConfig();
    }

    /**
     * Validate configuration for security
     *
     * @throws \InvalidArgumentException For insecure settings
     */
    private static function validateConfig(): void
    {
        if (self::$config['digits'] < 6 || self::$config['digits'] > 8) {
            throw new \InvalidArgumentException('Digits must be between 6 and 8');
        }

        if (self::$config['period'] < 30) {
            throw new \InvalidArgumentException('Period must be at least 30 seconds');
        }

        if (self::$config['secret_length'] < 16) {
            throw new \InvalidArgumentException('Secret length must be at least 16 bytes (128 bits)');
        }

        if (!in_array(self::$config['algorithm'], self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                'Algorithm must be one of: ' . implode(', ', self::SUPPORTED_ALGORITHMS)
            );
        }

        if (self::$config['time_tolerance'] < 0 || self::$config['time_tolerance'] > 2) {
            throw new \InvalidArgumentException('Time tolerance must be between 0 and 2');
        }
    }

    /**
     * Get current configuration
     *
     * @return array Current configuration
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Generate a cryptographically secure Base32-encoded secret
     *
     * @return string Base32-encoded secret
     */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(self::$config['secret_length']);
        return self::base32Encode($bytes);
    }

    /**
     * Validate secret format
     *
     * @param string $secret Base32-encoded secret
     * @return bool True if valid
     */
    public static function isValidSecret(string $secret): bool
    {
        // Remove any spaces or dashes (common formatting)
        $secret = preg_replace('/[\s-]/', '', strtoupper($secret));

        if (strlen($secret) < 16) {
            return false;
        }

        // Check for valid Base32 characters only
        return preg_match('/^[A-Z2-7]+$/', $secret) === 1;
    }

    /**
     * Generate TOTP code for given secret and timestamp
     *
     * @param string $secret Base32-encoded secret
     * @param int|null $timestamp Unix timestamp (default: current time)
     * @return string TOTP code (zero-padded)
     */
    public static function getCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::$config['period']);

        // Decode secret
        $secretBytes = self::base32Decode($secret);

        // Pack counter as 8-byte big-endian
        $counterBytes = pack('J', $counter);

        // Compute HMAC
        $hash = hash_hmac(self::$config['algorithm'], $counterBytes, $secretBytes, true);

        // Dynamic truncation
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::$config['digits']);

        return str_pad((string)$otp, self::$config['digits'], '0', STR_PAD_LEFT);
    }

    /**
     * Verify TOTP code with timing-safe comparison
     *
     * @param string $secret Base32-encoded secret
     * @param string $code User-provided code
     * @param int|null $usedTimestamp Reference to store the timestamp window used (for replay prevention)
     * @return bool True if code is valid
     */
    public static function verify(string $secret, string $code, ?int &$usedTimestamp = null): bool
    {
        if (!self::isValidSecret($secret)) {
            return false;
        }

        // Normalize code (remove spaces)
        $code = preg_replace('/\s/', '', $code);

        if (strlen($code) !== self::$config['digits']) {
            return false;
        }

        $timestamp = time();
        $tolerance = self::$config['time_tolerance'];

        // Check current and adjacent time windows
        for ($i = -$tolerance; $i <= $tolerance; $i++) {
            $checkTime = $timestamp + ($i * self::$config['period']);
            $expectedCode = self::getCode($secret, $checkTime);

            // Timing-safe comparison
            if (hash_equals($expectedCode, $code)) {
                $usedTimestamp = intdiv($checkTime, self::$config['period']) * self::$config['period'];
                return true;
            }
        }

        return false;
    }

    /**
     * Verify TOTP code with replay attack prevention
     *
     * @param string $secret Base32-encoded secret
     * @param string $code User-provided code
     * @param int|null $lastUsedTimestamp Last successful verification timestamp (null if never used)
     * @return int|false New timestamp to store on success, false on failure
     */
    public static function verifyWithReplayProtection(string $secret, string $code, ?int $lastUsedTimestamp): int|false
    {
        $usedTimestamp = null;

        if (!self::verify($secret, $code, $usedTimestamp)) {
            return false;
        }

        // Check for replay attack
        if ($lastUsedTimestamp !== null && $usedTimestamp <= $lastUsedTimestamp) {
            return false;
        }

        return $usedTimestamp;
    }

    /**
     * Generate otpauth:// provisioning URI for authenticator apps
     *
     * @param string $secret Base32-encoded secret
     * @param string $account Account identifier (email or username)
     * @return string otpauth:// URI
     */
    public static function getProvisioningUri(string $secret, string $account): string
    {
        $issuer = rawurlencode(self::$config['issuer']);
        $account = rawurlencode($account);
        $secret = strtoupper(preg_replace('/[\s-]/', '', $secret));

        $uri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=%d&period=%d&algorithm=%s',
            $issuer,
            $account,
            $secret,
            $issuer,
            self::$config['digits'],
            self::$config['period'],
            strtoupper(self::$config['algorithm'])
        );

        return $uri;
    }

    /**
     * Generate QR code as base64 data URI for authenticator app setup
     *
     * @param string $secret Base32-encoded secret
     * @param string $account Account identifier (email or username)
     * @return string Data URI (data:image/png;base64,...)
     */
    public static function getQrCodeDataUri(string $secret, string $account): string
    {
        $uri = self::getProvisioningUri($secret, $account);
        return QRCode::toDataUri($uri, self::$config['qr_size'], self::$config['qr_margin']);
    }

    /**
     * Generate QR code as raw PNG binary
     *
     * @param string $secret Base32-encoded secret
     * @param string $account Account identifier (email or username)
     * @return string PNG binary data
     */
    public static function getQrCodePng(string $secret, string $account): string
    {
        $uri = self::getProvisioningUri($secret, $account);
        return QRCode::generate($uri, self::$config['qr_size'], self::$config['qr_margin']);
    }

    /**
     * Generate cryptographically secure backup codes
     *
     * @return array Array of plaintext backup codes (store hashed!)
     */
    public static function generateBackupCodes(): array
    {
        $codes = [];
        $charset = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Removed I, O to avoid confusion
        $charsetLength = strlen($charset);

        for ($i = 0; $i < self::$config['backup_codes_count']; $i++) {
            $code = '';
            $bytes = random_bytes(self::$config['backup_code_length']);

            for ($j = 0; $j < self::$config['backup_code_length']; $j++) {
                $code .= $charset[ord($bytes[$j]) % $charsetLength];
            }

            // Format with dash for readability (e.g., ABCD-EFGH)
            if (self::$config['backup_code_length'] >= 8) {
                $code = substr($code, 0, 4) . '-' . substr($code, 4);
            }

            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * Hash a backup code for secure storage using Argon2id
     *
     * @param string $code Plaintext backup code
     * @return string Argon2id hash
     */
    public static function hashBackupCode(string $code): string
    {
        // Normalize: remove dashes and convert to uppercase
        $normalized = strtoupper(preg_replace('/[\s-]/', '', $code));

        return password_hash($normalized, PASSWORD_ARGON2ID, [
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
        ]);
    }

    /**
     * Verify a backup code against stored hashes
     *
     * @param string $code User-provided backup code
     * @param array $hashedCodes Array of Argon2id hashed codes
     * @return int|false Index of matched code on success (remove it!), false on failure
     */
    public static function verifyBackupCode(string $code, array $hashedCodes): int|false
    {
        // Normalize: remove dashes and convert to uppercase
        $normalized = strtoupper(preg_replace('/[\s-]/', '', $code));

        foreach ($hashedCodes as $index => $hashedCode) {
            if (password_verify($normalized, $hashedCode)) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Get remaining seconds until current code expires
     *
     * @return int Seconds remaining
     */
    public static function getSecondsRemaining(): int
    {
        return self::$config['period'] - (time() % self::$config['period']);
    }

    /**
     * Encode binary data as Base32
     *
     * @param string $data Binary data
     * @return string Base32-encoded string
     */
    private static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);

        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $result .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $result;
    }

    /**
     * Decode Base32 string to binary data
     *
     * @param string $data Base32-encoded string
     * @return string Binary data
     */
    private static function base32Decode(string $data): string
    {
        // Normalize: uppercase, remove padding and spaces
        $data = strtoupper(preg_replace('/[\s=-]/', '', $data));

        $binary = '';
        foreach (str_split($data) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue; // Skip invalid characters
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 8);

        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr(bindec($chunk));
            }
        }

        return $result;
    }
}
