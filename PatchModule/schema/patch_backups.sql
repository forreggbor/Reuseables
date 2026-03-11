-- patch_backups table
-- Used by MysqldumpBackupAdapter to record pre-patch database dumps.

CREATE TABLE IF NOT EXISTS patch_backups (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(255)         NOT NULL,
    file_size  BIGINT UNSIGNED      DEFAULT 0,
    note       VARCHAR(500)         DEFAULT NULL,
    created_at DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
