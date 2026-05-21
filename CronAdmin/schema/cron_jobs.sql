-- CronAdmin module — cron_jobs table (greenfield install).
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS.
-- No foreign keys — cross-application FKs are not allowed in reusable modules.
-- updated_by, trigger_pending_by: bare INT NULL with comment "External user reference (application-managed)".

CREATE TABLE IF NOT EXISTS `cron_jobs` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_key`               VARCHAR(64)  NOT NULL COMMENT 'Matches /^[a-z0-9_]+$/ — unique manifest key',
    `name_key`              VARCHAR(96)  NOT NULL COMMENT 'Translation key resolved by host __() — e.g. TEXT_JOB_BACKUP',
    `description_key`       VARCHAR(96)  NOT NULL DEFAULT '' COMMENT 'Translation key for the description tooltip',
    `frequency`             ENUM('every_n_minutes','hourly','daily','weekly','monthly') NOT NULL,
    `every_n_minutes`       SMALLINT UNSIGNED NULL COMMENT 'One of: 1,5,10,15,20,30,60,120,180,240,360,720,1440',
    `hour`                  TINYINT UNSIGNED NULL COMMENT '0–23',
    `minute`                TINYINT UNSIGNED NULL COMMENT '0–59',
    `days_of_week`          VARCHAR(13) NULL COMMENT 'CSV of 0..6 (0=Sun) — e.g. "0,3,5"',
    `days_of_month`         VARCHAR(96) NULL COMMENT 'CSV of 1..31 — e.g. "1,15"',
    `email_report`          ENUM('off','on_failure','every_run') NOT NULL DEFAULT 'off',
    `log_to_db`             TINYINT(1) NOT NULL DEFAULT 0,
    `enabled`               TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'Admin on/off toggle',
    `active`                TINYINT(1) NOT NULL DEFAULT 1  COMMENT '0 = removed from manifest (soft-deleted); history preserved',
    `lock_timeout_seconds`  INT UNSIGNED NOT NULL DEFAULT 3600 COMMENT 'Stale-lock reclaim threshold',
    `last_run_at`           DATETIME NULL,
    `last_status`           ENUM('success','failure','skipped') NULL,
    `last_error`            VARCHAR(1024) NULL COMMENT 'Error or skip-reason sentinel: exception, locked, lock_dir_unavailable, class_not_found, …',
    `last_output_excerpt`   MEDIUMTEXT NULL COMMENT 'UTF-8-safe truncated to 8 KB by JobRunner',
    `last_duration_ms`      INT UNSIGNED NULL,
    `trigger_pending`       TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Run-Now claim flag — atomically set by AdminActions',
    `trigger_pending_at`    DATETIME NULL,
    `trigger_pending_by`    INT NULL COMMENT 'External user reference (application-managed)',
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`            INT NULL COMMENT 'External user reference (application-managed)',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_cron_jobs_job_key` (`job_key`),
    KEY `idx_cron_jobs_active`    (`active`),
    KEY `idx_cron_jobs_trigger`   (`trigger_pending`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
