-- LicenseModule Database Schema
-- Required tables for license validation and history tracking
-- Compatible with MySQL/MariaDB
-- Character set: utf8mb4 for full Unicode support

-- License information table
CREATE TABLE IF NOT EXISTS license_info (
    id INT PRIMARY KEY AUTO_INCREMENT,
    license_key VARCHAR(255) UNIQUE NOT NULL,
    license_type VARCHAR(50) COMMENT 'Tier slug (core, standard, advanced, pro)',
    licensed_domain VARCHAR(255),
    validated_at DATETIME NULL COMMENT 'Last successful validation timestamp',
    expires_at DATETIME NULL COMMENT 'License expiration date',
    features JSON COMMENT 'License features including tier, addons, and feature_keys',
    last_check_at DATETIME NULL COMMENT 'Last validation attempt timestamp',
    status ENUM('active', 'expired', 'invalid', 'suspended') DEFAULT 'active',
    validation_frequency INT DEFAULT 24 COMMENT 'Hours between validations',
    notification_sent_at DATETIME NULL COMMENT 'Last notification sent timestamp',
    grace_period_days INT DEFAULT 7 COMMENT 'Days before entering read-only mode when offline',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- License validation history table
CREATE TABLE IF NOT EXISTS license_validation_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    license_id INT NOT NULL,
    validation_time DATETIME NOT NULL,
    status ENUM('success', 'expired', 'invalid', 'suspended', 'error') NOT NULL,
    response_data JSON COMMENT 'Full server response for debugging',
    error_message TEXT COMMENT 'Error details if validation failed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_license_id (license_id),
    INDEX idx_validation_time (validation_time),
    CONSTRAINT fk_license_validation_license
        FOREIGN KEY (license_id) REFERENCES license_info(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example: Insert initial license record (replace with actual license key)
-- INSERT INTO license_info (license_key, status)
-- VALUES ('YOUR-LICENSE-KEY-HERE', 'active');
