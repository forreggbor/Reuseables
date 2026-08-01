-- PatchModule Database Schema
-- Requires: MySQL 5.7+ / MariaDB 10.2+
--
-- Tables:
--   patch_history  - Tracks patch lifecycle (available → installed/failed/rolled_back)
--   patch_settings - Simple key-value cache for patch check results
--
-- Note: No foreign keys to application-specific tables (users, backups).
-- The installed_by and backup_id columns store external references managed
-- by the host application through adapters.

CREATE TABLE IF NOT EXISTS patch_history (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    version VARCHAR(20) NOT NULL COMMENT 'Target patch version (semver)',
    previous_version VARCHAR(20) NULL COMMENT 'Application version before patch was applied',
    status ENUM('available','downloading','installing','completed','failed','rolled_back','obsolete') NOT NULL DEFAULT 'available' COMMENT 'Patch lifecycle status',
    release_notes MEDIUMTEXT NULL COMMENT 'Markdown release notes from patch server',
    file_size BIGINT UNSIGNED NULL COMMENT 'Patch archive file size in bytes',
    sha256_hash VARCHAR(64) NULL COMMENT 'Expected SHA-256 hash of the patch archive',
    patch_server_id INT UNSIGNED NULL COMMENT 'Patch ID on the remote patch server; NULL = manually uploaded',
    backup_id INT NULL COMMENT 'External backup reference (application-managed)',
    error_message TEXT NULL COMMENT 'Error details on failure',
    installed_by INT NULL COMMENT 'External user reference (application-managed)',
    checked_at DATETIME NULL COMMENT 'When patch availability was last checked',
    downloaded_at DATETIME NULL COMMENT 'When patch archive was downloaded',
    installed_at DATETIME NULL COMMENT 'When installation completed successfully',
    rolled_back_at DATETIME NULL COMMENT 'When rollback was triggered',
    released_at DATETIME NULL COMMENT 'Server-side release date',
    manifest_json JSON NULL COMMENT 'Stored manifest.json from the patch package',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ph_status (status),
    INDEX idx_ph_version (version),
    INDEX idx_ph_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patch_settings (
    setting_key VARCHAR(100) PRIMARY KEY COMMENT 'Setting identifier',
    setting_value MEDIUMTEXT NULL COMMENT 'Setting value (JSON for complex data)',
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;