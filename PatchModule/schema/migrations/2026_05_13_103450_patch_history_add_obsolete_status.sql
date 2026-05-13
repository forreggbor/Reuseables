-- PatchModule Migration: Add 'obsolete' status to patch_history
--
-- Extends the status ENUM to include the 'obsolete' value, used for patches
-- that have been yanked from the patch server or superseded by a direct file-copy
-- install. Obsolete patches remain in history but are excluded from the available
-- patches list.
--
-- Safe to run multiple times (ALTER TABLE is idempotent for ENUM extension on MariaDB).

ALTER TABLE `patch_history`
    MODIFY COLUMN `status`
        ENUM('available','downloading','installing','completed','failed','rolled_back','obsolete')
        NOT NULL DEFAULT 'available'
        COMMENT 'Patch lifecycle status';
