-- MFA Database Schema Template
-- Copy and adapt to your project's users table
--
-- Two options provided:
--   Option A: Add columns to existing users table (recommended for most projects)
--   Option B: Create separate mfa_settings table with foreign key
--
-- Choose ONE option based on your architecture preference.

-- =============================================================================
-- OPTION A: Add columns to existing users table
-- =============================================================================
-- Recommended for simpler projects where MFA is tightly coupled with user records

ALTER TABLE users ADD COLUMN mfa_secret VARCHAR(32) DEFAULT NULL
    COMMENT 'Base32 encoded TOTP secret (160-bit, 32 chars)';

ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) UNSIGNED DEFAULT 0
    COMMENT 'MFA activation status: 0=disabled, 1=enabled';

ALTER TABLE users ADD COLUMN mfa_last_used INT UNSIGNED DEFAULT NULL
    COMMENT 'Unix timestamp of last successful TOTP verification (replay prevention)';

ALTER TABLE users ADD COLUMN mfa_backup_codes TEXT DEFAULT NULL
    COMMENT 'JSON array of Argon2id hashed backup codes';

ALTER TABLE users ADD COLUMN mfa_failed_attempts TINYINT UNSIGNED DEFAULT 0
    COMMENT 'Consecutive failed MFA verification attempts';

ALTER TABLE users ADD COLUMN mfa_locked_until DATETIME DEFAULT NULL
    COMMENT 'Account MFA lockout expiry timestamp (NULL = not locked)';

-- Optional: Index for filtering users by MFA status
CREATE INDEX idx_users_mfa_enabled ON users(mfa_enabled);


-- =============================================================================
-- OPTION B: Separate table with foreign key
-- =============================================================================
-- Recommended for larger projects or when MFA settings need independent management
-- Uncomment the block below if using this option

/*
CREATE TABLE mfa_settings (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY
        COMMENT 'References users.id',
    secret VARCHAR(32) NOT NULL
        COMMENT 'Base32 encoded TOTP secret (160-bit)',
    enabled TINYINT(1) UNSIGNED DEFAULT 1
        COMMENT 'MFA activation status',
    last_used INT UNSIGNED DEFAULT NULL
        COMMENT 'Last successful TOTP timestamp',
    backup_codes TEXT DEFAULT NULL
        COMMENT 'JSON array of Argon2id hashed backup codes',
    failed_attempts TINYINT UNSIGNED DEFAULT 0
        COMMENT 'Consecutive failed attempts',
    locked_until DATETIME DEFAULT NULL
        COMMENT 'Lockout expiry timestamp',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        COMMENT 'MFA enrollment timestamp',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Last modification timestamp',

    CONSTRAINT fk_mfa_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Multi-factor authentication settings per user';
*/


-- =============================================================================
-- COLUMN REFERENCE
-- =============================================================================
--
-- mfa_secret:
--   - Store the Base32-encoded secret returned by MFAuthenticator::generateSecret()
--   - Consider encrypting at rest for additional security
--   - VARCHAR(32) accommodates 160-bit (20 byte) secrets
--
-- mfa_enabled:
--   - Set to 1 only after user confirms MFA setup by entering valid code
--   - Use for conditional MFA enforcement during login
--
-- mfa_last_used:
--   - Store return value from MFAuthenticator::verifyWithReplayProtection()
--   - Prevents replay attacks (same code used twice)
--   - Unix timestamp format
--
-- mfa_backup_codes:
--   - Store as JSON array of Argon2id hashes
--   - Example: ["$argon2id$...", "$argon2id$..."]
--   - Remove used codes from array after successful verification
--
-- mfa_failed_attempts:
--   - Increment on each failed MFA verification
--   - Reset to 0 on successful verification
--   - Lock account when threshold reached (recommended: 5)
--
-- mfa_locked_until:
--   - Set expiry timestamp when failed_attempts threshold reached
--   - Recommended lockout duration: 15 minutes
--   - Check this before allowing MFA verification attempts
