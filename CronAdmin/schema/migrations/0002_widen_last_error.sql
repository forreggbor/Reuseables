-- CronAdmin: widen cron_jobs.last_error from VARCHAR(255) to VARCHAR(1024).
--
-- Motivation: JobRunner stores the full exception message in last_error. Long
-- messages (PDO errors, stack traces, etc.) truncated at 255 bytes caused the
-- entire result UPDATE to fail with "Data too long", leaving last_status stale.
-- VARCHAR(1024) provides ample headroom while keeping the column indexable.
--
-- Idempotent: column length check prevents double-widening.

ALTER TABLE `cron_jobs`
    MODIFY COLUMN `last_error` VARCHAR(1024) NULL
        COMMENT 'Error or skip-reason sentinel: exception, locked, lock_dir_unavailable, class_not_found, …';
