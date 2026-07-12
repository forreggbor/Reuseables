<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Maps action names to badge CSS color classes for the activity log viewer.
 */

declare(strict_types=1);

namespace ActivityLogs;

/**
 * ActionColorResolver - action name to badge color class mapper
 *
 * Applies prefix/suffix-based rules (create_ -> green, update_ -> amber,
 * delete_ -> red, login/logout -> blue, export_/import_/upload_/download_/restore_
 * -> purple) and an optional host-supplied exact-match override map. The
 * "_failed"/"_error" suffix (checked ahead of every prefix rule) always wins,
 * so a failed create_ or restore_ action still reads as red rather than its
 * base action's color. Returns a CSS class name with the .al-badge- prefix.
 *
 * @package ActivityLogs
 */
class ActionColorResolver
{
    /** @var array<string,string> Exact-match overrides: action → color key */
    private array $overrides = [];

    /**
     * Add or replace exact-match color overrides.
     *
     * @param array<string,string> $map action → color key ('green','amber','red','blue','grey')
     * @return void
     */
    public function addOverrides(array $map): void
    {
        $this->overrides = array_merge($this->overrides, $map);
    }

    /**
     * Resolve an action name to a badge CSS class.
     *
     * @param string $action Action name (e.g. 'create_product', 'user_login')
     * @return string CSS class string (e.g. 'al-badge-green')
     */
    public function resolve(string $action): string
    {
        if (isset($this->overrides[$action])) {
            return 'al-badge-' . $this->overrides[$action];
        }

        return 'al-badge-' . $this->matchPrefix($action);
    }

    /**
     * Apply prefix/suffix rules to determine a color key.
     *
     * The `_failed`/`_error` suffix check runs FIRST, ahead of every prefix rule —
     * a failure must always read as red regardless of what kind of operation
     * failed. Checking it after the prefix rules (the original order) let an
     * action like `create_full_backup_failed` match the `create_` prefix before
     * ever reaching the failure check, showing a failed operation in the same
     * green used for a genuine success.
     *
     * @param string $action Action name
     * @return string Color key: green, amber, red, blue, purple, or grey
     */
    private function matchPrefix(string $action): string
    {
        if (str_ends_with($action, '_failed') || str_ends_with($action, '_error')
            || str_starts_with($action, 'failed_') || str_starts_with($action, 'error_')) {
            return 'red';
        }
        if (str_starts_with($action, 'create_') || str_starts_with($action, 'insert_') || str_starts_with($action, 'add_')) {
            return 'green';
        }
        if (str_starts_with($action, 'update_') || str_starts_with($action, 'edit_') || str_starts_with($action, 'change_')) {
            return 'amber';
        }
        if (str_starts_with($action, 'delete_') || str_starts_with($action, 'remove_') || str_starts_with($action, 'destroy_')) {
            return 'red';
        }
        if (str_ends_with($action, '_login') || str_starts_with($action, 'login') || str_ends_with($action, '_logout') || str_starts_with($action, 'logout')) {
            return 'blue';
        }
        if (str_starts_with($action, 'export_') || str_starts_with($action, 'import_') || str_starts_with($action, 'upload_')
            || str_starts_with($action, 'download_') || str_starts_with($action, 'restore_')) {
            return 'purple';
        }
        return 'grey';
    }
}
