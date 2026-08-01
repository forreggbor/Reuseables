-- BackupRestore module schema — backups
-- Run LAST — references both backup_profiles and backup_remote_servers.
--
-- IMPORTANT: `created_by` is a plain nullable INT with NO foreign key to a
-- host `users` table. This module never JOINs a host user table directly —
-- creator display names are resolved via the injected `get_user_map`
-- callable (see BackupEngine::attachCreatorNames()), so the schema stays
-- self-contained and installable on a host whose user table has any name,
-- shape, or engine. Do NOT add a users FK here.
--
-- Table name is configurable via facade config (table_names.backups); adjust
-- this file's table name (and the FK targets) to match if you override the
-- defaults.

CREATE TABLE IF NOT EXISTS backups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_id INT NULL COMMENT 'Profile used for this backup (NULL = manual)',
    filename VARCHAR(255) NOT NULL,
    type ENUM('full', 'database', 'files') NOT NULL DEFAULT 'full',
    size_bytes BIGINT UNSIGNED NULL,
    tables_count INT UNSIGNED NULL,
    checksum_sha256 VARCHAR(64) NULL,
    restore_token VARCHAR(64) NULL COMMENT 'Unique token for standalone restore authentication',
    status ENUM('in_progress', 'completed', 'failed') NOT NULL DEFAULT 'in_progress',
    note TEXT NULL,
    included_paths JSON NULL COMMENT 'Folders included in this backup',
    excluded_paths JSON NULL COMMENT 'Folders excluded from this backup',
    remote_synced TINYINT(1) NOT NULL DEFAULT 0,
    remote_server_id INT NULL COMMENT 'Remote server where backup was transferred',
    file_deleted_at DATETIME NULL COMMENT 'File removed but history kept',
    error_message TEXT NULL,
    created_by INT NULL COMMENT 'User who created the backup (NULL = system/scheduled); no FK — see note above',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_backups_status (status),
    INDEX idx_backups_type (type),
    INDEX idx_backups_created (created_at),
    INDEX idx_backups_profile (profile_id),
    INDEX idx_backups_remote (remote_server_id),
    INDEX idx_backups_creator (created_by),
    CONSTRAINT fk_backups_profile FOREIGN KEY (profile_id)
        REFERENCES backup_profiles (id) ON DELETE SET NULL,
    CONSTRAINT fk_backups_remote_server FOREIGN KEY (remote_server_id)
        REFERENCES backup_remote_servers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
