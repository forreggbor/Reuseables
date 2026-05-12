-- patch_migrations: tracks SQL migration files executed by PatchModule.
-- Load order: patch_history.sql first (FK target), then patch_backups.sql, then this file.
-- For existing installations upgrading to v1.8.0, this table is created automatically
-- by PatchMigrator::ensureMigrationsTable() on first use — no manual step required.
CREATE TABLE IF NOT EXISTS `patch_migrations` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patch_history_id` INT UNSIGNED NULL,
    `filename`         VARCHAR(255) NOT NULL,
    `executed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_patch_migrations_filename` (`filename`),
    KEY `idx_patch_migrations_history` (`patch_history_id`),
    CONSTRAINT `fk_patch_migrations_history`
        FOREIGN KEY (`patch_history_id`) REFERENCES `patch_history` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
