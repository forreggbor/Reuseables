-- PatchModule Migration: Widen patch_settings.setting_value from TEXT to MEDIUMTEXT
--
-- TEXT columns are capped at 65,535 bytes. The cached patch_available_data JSON
-- blob embeds full dual-language (HU + EN) release notes; on a multi-version
-- cumulative update the encoded payload can exceed that limit, crashing the
-- "check for updates" flow with SQLSTATE[22001] Data too long.
--
-- patch_history.release_notes was already widened for the same reason (see
-- 2026_05_27_150920_patch_history_release_notes_mediumtext.sql); this column
-- never received the matching fix.
--
-- Non-destructive: existing rows are unaffected; the widening only raises the
-- ceiling. Safe to run multiple times (MODIFY COLUMN is idempotent when the
-- target type is the same as the current column definition).

ALTER TABLE `patch_settings`
    MODIFY COLUMN `setting_value`
        MEDIUMTEXT NULL
        COMMENT 'Setting value (JSON for complex data)';
