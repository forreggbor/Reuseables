-- BackupRestore module schema — backup_profiles
-- Self-contained except for one intra-module FK to backup_remote_servers —
-- run schema/backup_remote_servers.sql FIRST. No foreign keys to host tables.
-- Table name is configurable via facade config (table_names.backup_profiles);
-- adjust this file's table name (and the FK target) to match if you override
-- the defaults.

CREATE TABLE IF NOT EXISTS backup_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    type ENUM('full', 'database', 'files') NOT NULL DEFAULT 'full',
    include_database TINYINT(1) NOT NULL DEFAULT 1,
    included_paths JSON NULL COMMENT 'Folders to include in backup (NULL = all)',
    excluded_paths JSON NULL COMMENT 'Folders to exclude from backup',
    remote_server_id INT NULL COMMENT 'Remote server for automatic transfer',
    schedule_enabled TINYINT(1) NOT NULL DEFAULT 0,
    schedule_type ENUM('daily', 'weekly', 'monthly') NULL,
    schedule_time TIME NULL COMMENT 'Time of day for scheduled backup',
    schedule_day TINYINT NULL COMMENT '0-6 for weekly (0=Sunday), 1-28 for monthly',
    retention_days INT UNSIGNED NOT NULL DEFAULT 30,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_status ENUM('success', 'failure') NULL COMMENT 'Outcome of the most recent scheduled execution; NULL if never run',
    last_error VARCHAR(1024) NULL COMMENT 'Error message from the most recent failed execution; NULL on success or never run',
    next_run_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bp_active (is_active),
    INDEX idx_bp_schedule (schedule_enabled, next_run_at),
    INDEX idx_bp_remote (remote_server_id),
    CONSTRAINT fk_bp_remote_server FOREIGN KEY (remote_server_id)
        REFERENCES backup_remote_servers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
