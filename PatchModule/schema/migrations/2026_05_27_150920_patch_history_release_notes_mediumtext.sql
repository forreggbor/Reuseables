-- PatchModule Migration: Widen release_notes from TEXT to MEDIUMTEXT
--
-- TEXT columns are capped at 65,535 bytes. Dual-language release notes produced by
-- PatchCreator v1.09.00+ can exceed that limit. MEDIUMTEXT supports up to 16 MB,
-- which is well beyond any realistic release-notes size.
--
-- Non-destructive: existing rows are unaffected; the widening only raises the ceiling.
-- Safe to run multiple times (MODIFY COLUMN is idempotent when the target type is the
-- same as the current column definition).

ALTER TABLE `patch_history`
    MODIFY COLUMN `release_notes`
        MEDIUMTEXT NULL
        COMMENT 'Markdown release notes from patch server';
