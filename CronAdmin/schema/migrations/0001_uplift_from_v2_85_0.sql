-- CronAdmin module — migration 0001
-- Uplifts a JupitERP v2.85.0-shape cron_jobs table to the CronAdmin module schema.
-- Idempotent: all steps are guarded by INFORMATION_SCHEMA checks; safe to re-run.
--
-- WARNING: Do NOT run this migration on a JupitERP instance until the CronAdmin
-- integration plan has rewired every label_key reference in JupitERP application
-- code (CronRunnerService, CronController, CronJobRegistry, admin view, etc.).
-- The CHANGE COLUMN step below renames label_key → name_key and will break live
-- code instantly on any host that still references label_key. Greenfield hosts
-- that never had label_key are unaffected.
--
-- If the migration is interrupted (e.g. disk full), simply re-run — each step is
-- guarded and will skip steps already applied.

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 1: Add `active` column if missing.
--         Place it AFTER `trigger_pending_at` when that column exists,
--         otherwise append at table end.
-- ─────────────────────────────────────────────────────────────────────────────
SET @col_exists    := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME   = 'cron_jobs'
                         AND COLUMN_NAME  = 'active');

SET @anchor_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME   = 'cron_jobs'
                         AND COLUMN_NAME  = 'trigger_pending_at');

SET @add_active_anchored := 'ALTER TABLE cron_jobs
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1
        AFTER trigger_pending_at,
    ADD KEY idx_cron_jobs_active (active)';

SET @add_active_unanchored := 'ALTER TABLE cron_jobs
    ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1,
    ADD KEY idx_cron_jobs_active (active)';

SET @sql := IF(@col_exists = 0,
               IF(@anchor_exists = 1, @add_active_anchored, @add_active_unanchored),
               'DO 0');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 2: Rename label_key → name_key (only when old column exists and new one does not).
-- ─────────────────────────────────────────────────────────────────────────────
SET @old_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'cron_jobs'
                      AND COLUMN_NAME  = 'label_key');

SET @new_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'cron_jobs'
                      AND COLUMN_NAME  = 'name_key');

SET @sql := IF(@old_exists = 1 AND @new_exists = 0,
               'ALTER TABLE cron_jobs
                    CHANGE COLUMN label_key name_key VARCHAR(96) NOT NULL',
               'DO 0');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 3: Add `description_key` column if missing.
-- ─────────────────────────────────────────────────────────────────────────────
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'cron_jobs'
                      AND COLUMN_NAME  = 'description_key');

SET @name_key_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME   = 'cron_jobs'
                           AND COLUMN_NAME  = 'name_key');

SET @add_desc_anchored   := 'ALTER TABLE cron_jobs ADD COLUMN description_key VARCHAR(96) NOT NULL DEFAULT "" AFTER name_key';
SET @add_desc_unanchored := 'ALTER TABLE cron_jobs ADD COLUMN description_key VARCHAR(96) NOT NULL DEFAULT ""';

SET @sql := IF(@col_exists = 0,
               IF(@name_key_exists = 1, @add_desc_anchored, @add_desc_unanchored),
               'DO 0');

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
