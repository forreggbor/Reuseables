# MFA - Multi-Factor Authentication

RFC 6238 compliant TOTP (Time-based One-Time Password) implementation for PHP. Compatible with Google Authenticator, Authy, Microsoft Authenticator, and other TOTP apps.

## Features

- TOTP code generation and verification
- Built-in QR code generator (no external dependencies)
- Cryptographically secure secret generation (CSPRNG)
- Timing-safe code verification (prevents timing attacks)
- Replay attack prevention support
- Argon2id hashed backup codes
- Configurable time tolerance for clock drift
- Zero external dependencies

## Requirements

- PHP 8.3+
- `random_bytes()` function (standard in PHP 7+)
- `hash_hmac()` function (standard)
- `password_hash()` with Argon2id support (PHP 7.3+)

## Installation

Copy the `MFA` folder to your project's library directory:

```bash
cp -r MFA /path/to/your/project/lib/
```

## Quick Start

```php
<?php
require_once 'MFA/MFAuthenticator.php';
require_once 'MFA/QRCode.php';

use MFA\MFAuthenticator;

// Initialize with your app name
MFAuthenticator::init(['issuer' => 'MyApp']);

// Generate secret for new user
$secret = MFAuthenticator::generateSecret();

// Generate QR code for authenticator app
$qrDataUri = MFAuthenticator::getQrCodeDataUri($secret, 'user@example.com');
echo "<img src='{$qrDataUri}' alt='Scan with authenticator app'>";

// Verify code entered by user
if (MFAuthenticator::verify($secret, $_POST['code'])) {
    echo 'MFA verification successful!';
}
```

## Database Setup

Apply the schema template to your database. See [`schema.sql`](schema.sql) for the complete migration with two options:

**Option A** - Add columns to existing users table:
```sql
ALTER TABLE users ADD COLUMN mfa_secret VARCHAR(32) DEFAULT NULL;
ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN mfa_last_used INT UNSIGNED DEFAULT NULL;
ALTER TABLE users ADD COLUMN mfa_backup_codes TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN mfa_failed_attempts TINYINT UNSIGNED DEFAULT 0;
ALTER TABLE users ADD COLUMN mfa_locked_until DATETIME DEFAULT NULL;
```

**Option B** - Create separate `mfa_settings` table (see `schema.sql` for full definition).

## Configuration

```php
MFAuthenticator::init([
    'issuer' => 'MyApp',           // App name in authenticator
    'digits' => 6,                  // Code length (6-8)
    'period' => 30,                 // Time step in seconds (min 30)
    'algorithm' => 'sha1',          // sha1, sha256, or sha512
    'secret_length' => 20,          // Secret bytes (min 16)
    'time_tolerance' => 1,          // Accept codes ±1 period
    'backup_codes_count' => 8,      // Number of backup codes
    'backup_code_length' => 8,      // Backup code length
    'qr_size' => 4,                 // QR module size in pixels
    'qr_margin' => 4,               // QR margin in modules
]);
```

## API Reference

### Secret Management

| Method | Description |
|--------|-------------|
| `generateSecret(): string` | Generate cryptographic Base32 secret |
| `isValidSecret(string $secret): bool` | Validate secret format |

### Code Generation & Verification

| Method | Description |
|--------|-------------|
| `getCode(string $secret, ?int $timestamp = null): string` | Generate TOTP code |
| `verify(string $secret, string $code, ?int &$usedTimestamp = null): bool` | Verify code with timing-safe comparison |
| `verifyWithReplayProtection(string $secret, string $code, ?int $lastUsed): int\|false` | Verify with replay attack prevention |
| `getSecondsRemaining(): int` | Seconds until current code expires |

### QR Code Generation

| Method | Description |
|--------|-------------|
| `getProvisioningUri(string $secret, string $account): string` | Generate otpauth:// URI |
| `getQrCodeDataUri(string $secret, string $account): string` | Generate base64 PNG data URI |
| `getQrCodePng(string $secret, string $account): string` | Generate raw PNG binary |

### Backup Codes

| Method | Description |
|--------|-------------|
| `generateBackupCodes(): array` | Generate plaintext backup codes |
| `hashBackupCode(string $code): string` | Hash code with Argon2id |
| `verifyBackupCode(string $code, array $hashedCodes): int\|false` | Verify and return matched index |

## Implementation Guide

### Step 1: MFA Enrollment

Display the QR code and prompt user to scan with authenticator app:

```php
// Generate secret and store temporarily
$secret = MFAuthenticator::generateSecret();
$_SESSION['pending_mfa_secret'] = $secret;

// Display QR code
$qr = MFAuthenticator::getQrCodeDataUri($secret, $user->email);
echo "<img src='{$qr}' alt='Scan with authenticator app'>";
echo "<p>Or enter manually: {$secret}</p>";
```

### Step 2: MFA Confirmation

Verify the user can generate codes before enabling MFA:

```php
$code = $_POST['code'];
$secret = $_SESSION['pending_mfa_secret'];

if (MFAuthenticator::verify($secret, $code)) {
    // Generate and hash backup codes
    $backupCodes = MFAuthenticator::generateBackupCodes();
    $hashedCodes = array_map([MFAuthenticator::class, 'hashBackupCode'], $backupCodes);

    // Save to database
    $stmt = $pdo->prepare('
        UPDATE users
        SET mfa_secret = ?, mfa_enabled = 1, mfa_backup_codes = ?
        WHERE id = ?
    ');
    $stmt->execute([$secret, json_encode($hashedCodes), $userId]);

    // Clear session
    unset($_SESSION['pending_mfa_secret']);

    // Show backup codes to user ONCE - they must save these!
    echo "<h2>Save these backup codes:</h2>";
    echo "<ul>";
    foreach ($backupCodes as $code) {
        echo "<li>{$code}</li>";
    }
    echo "</ul>";
} else {
    echo "Invalid code. Please try again.";
}
```

### Step 3: MFA Login Verification

Verify TOTP code during login with rate limiting and replay protection:

```php
// Check if account is locked
if ($user->mfa_locked_until && new DateTime() < new DateTime($user->mfa_locked_until)) {
    $remaining = (new DateTime($user->mfa_locked_until))->diff(new DateTime());
    throw new Exception("Account locked. Try again in {$remaining->i} minutes.");
}

// Verify code with replay protection
$newTimestamp = MFAuthenticator::verifyWithReplayProtection(
    $user->mfa_secret,
    $_POST['code'],
    $user->mfa_last_used
);

if ($newTimestamp !== false) {
    // Success - update timestamp and reset attempts
    $stmt = $pdo->prepare('
        UPDATE users
        SET mfa_last_used = ?, mfa_failed_attempts = 0, mfa_locked_until = NULL
        WHERE id = ?
    ');
    $stmt->execute([$newTimestamp, $userId]);

    // Proceed with login...
} else {
    // Failure - increment attempts
    $attempts = $user->mfa_failed_attempts + 1;
    $lockUntil = null;

    if ($attempts >= 5) {
        $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    }

    $stmt = $pdo->prepare('
        UPDATE users
        SET mfa_failed_attempts = ?, mfa_locked_until = ?
        WHERE id = ?
    ');
    $stmt->execute([$attempts, $lockUntil, $userId]);

    throw new Exception('Invalid MFA code.');
}
```

### Step 4: Backup Code Recovery

Allow login with backup code when authenticator is unavailable:

```php
$hashedCodes = json_decode($user->mfa_backup_codes, true) ?? [];
$usedIndex = MFAuthenticator::verifyBackupCode($_POST['backup_code'], $hashedCodes);

if ($usedIndex !== false) {
    // Remove used code
    unset($hashedCodes[$usedIndex]);

    $stmt = $pdo->prepare('
        UPDATE users
        SET mfa_backup_codes = ?, mfa_failed_attempts = 0, mfa_locked_until = NULL
        WHERE id = ?
    ');
    $stmt->execute([json_encode(array_values($hashedCodes)), $userId]);

    // Warn user about remaining codes
    $remaining = count($hashedCodes);
    if ($remaining <= 2) {
        // Prompt to generate new backup codes
    }

    // Proceed with login...
} else {
    throw new Exception('Invalid backup code.');
}
```

### Step 5: Disable MFA

Allow users to disable MFA (require current MFA code or backup code first):

```php
// Verify current MFA code before disabling
if (MFAuthenticator::verify($user->mfa_secret, $_POST['code'])) {
    $stmt = $pdo->prepare('
        UPDATE users
        SET mfa_secret = NULL, mfa_enabled = 0, mfa_last_used = NULL,
            mfa_backup_codes = NULL, mfa_failed_attempts = 0, mfa_locked_until = NULL
        WHERE id = ?
    ');
    $stmt->execute([$userId]);

    echo 'MFA has been disabled.';
}
```

## Security Best Practices

1. **Store secrets encrypted at rest** - Use database-level encryption or application-level encryption for the `mfa_secret` column.

2. **Never store plaintext backup codes** - Always use `hashBackupCode()` before storing.

3. **Implement replay protection** - Use `verifyWithReplayProtection()` and store `mfa_last_used` to prevent code reuse.

4. **Rate limit verification attempts** - Lock accounts after 5 failed attempts for 15 minutes.

5. **Log MFA events** - Track MFA enrollment, successful verifications, and failed attempts for security monitoring.

6. **Secure backup code display** - Show backup codes only once during enrollment. Require identity verification for regeneration.

7. **Require MFA for sensitive actions** - Re-verify MFA for password changes, email changes, and other security-critical operations.

## QRCode Class

The built-in QR code generator requires no external libraries:

```php
use MFA\QRCode;

// Generate PNG binary
$png = QRCode::generate('https://example.com', 4, 4);
file_put_contents('qrcode.png', $png);

// Generate data URI
$dataUri = QRCode::toDataUri('https://example.com', 4, 4);
echo "<img src='{$dataUri}'>";
```

Parameters:
- `$data` - String to encode
- `$moduleSize` - Size of each QR module in pixels (default: 4)
- `$margin` - Quiet zone margin in modules (default: 4)

## License

MIT License
