-- BackupRestore module schema — backup_remote_servers
-- Self-contained: no foreign keys to host tables (e.g. users). Create this
-- table FIRST — backup_profiles and backups both reference it.
-- Table name is configurable via facade config (table_names.backup_remote_servers);
-- adjust this file's table name to match if you override the default.

CREATE TABLE IF NOT EXISTS backup_remote_servers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('sftp', 'ssh') NOT NULL DEFAULT 'sftp',
    host VARCHAR(255) NOT NULL,
    port INT UNSIGNED NOT NULL DEFAULT 22,
    username VARCHAR(100) NOT NULL,
    auth_type ENUM('password', 'key') NOT NULL DEFAULT 'password',
    credentials TEXT NOT NULL COMMENT 'Encrypted via the injected EncryptorInterface',
    remote_path VARCHAR(500) NOT NULL DEFAULT '/backups',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    host_key_fingerprint VARCHAR(255) NULL COMMENT 'SSH host public key fingerprint, pinned on first successful connection (TOFU); mismatch on a later connection aborts it',
    last_connected DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_brs_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
