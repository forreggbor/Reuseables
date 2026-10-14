<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Regression fixture for Reusables#58 — the install modal rendered the "Create a backup before
 * installing" checkbox, checked, even when no backup adapter was active, so the box silently did
 * nothing. When the host reports `$backupAvailable = false` (no adapter, or an adapter that cannot work) the
 * modal shows a notice instead of the checkbox and its label; with a working adapter (or when the host does not
 * pass the flag, the old behaviour) it is unchanged.
 *
 * Plain script, no database: php tests/modal_backup_checkbox_test.php
 */

require_once __DIR__ . '/../src/PatchIcon.php';

$failures = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $name Description of what is asserted.
 * @param bool   $ok   Whether it holds.
 * @return void
 */
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

/**
 * Renders the modal partial in an isolated scope (the partial renders once per request, so the guard is reset).
 *
 * @param array<string,mixed> $vars Variables handed to the view (tr is added).
 * @return string HTML.
 */
function renderModal(array $vars): string
{
    unset($GLOBALS['__PATCH_MODAL_RENDERED']);
    $locale = require __DIR__ . '/../locale/en_US/messages.php';
    $vars['tr'] = static fn(string $key, mixed ...$p): string => $locale[$key] ?? ('MISSING ' . $key);
    return (static function (array $vars): string {
        extract($vars);
        ob_start();
        include __DIR__ . '/../views/admin/_modal.php';
        return (string)ob_get_clean();
    })($vars);
}

/**
 * Returns the opening tag of the backup checkbox, or null when it is not rendered.
 *
 * @param string $html Rendered modal.
 * @return string|null
 */
function checkboxTag(string $html): ?string
{
    return preg_match('/<input[^>]*id="patchCreateBackup"[^>]*>/', $html, $m) === 1 ? $m[0] : null;
}

$en = require __DIR__ . '/../locale/en_US/messages.php';
$hu = require __DIR__ . '/../locale/hu_HU/messages.php';

// No working backup: no checkbox and no label, a notice instead.
$none = renderModal(['backupAvailable' => false]);
check('without a working backup there is no checkbox', checkboxTag($none) === null);
check('without a working backup the label is not shown', !str_contains($none, htmlspecialchars($en['TEXT_PATCH_CREATE_BACKUP_BEFORE'])));
check('without a working backup a notice says so', isset($en['TEXT_PATCH_BACKUP_NOT_AVAILABLE']) && str_contains($none, htmlspecialchars($en['TEXT_PATCH_BACKUP_NOT_AVAILABLE'])));
check('without a working backup the "recommended" sentence is not shown', !str_contains($none, htmlspecialchars($en['TEXT_PATCH_BACKUP_RECOMMENDED'])));

// The install JS must cope with the missing checkbox (and then send create_backup=false).
$js = (string)file_get_contents(__DIR__ . '/../js/patch-update.js');
check('the install JS does not dereference the checkbox blindly', !str_contains($js, "getElementById('patchCreateBackup').checked") && str_contains($js, "getElementById('patchCreateBackup')"));

// With an adapter: exactly as before.
$with = renderModal(['backupAvailable' => true]);
$tag  = checkboxTag($with);
check('with an adapter the checkbox is checked', $tag !== null && (bool)preg_match('/\schecked(\s|=|>)/', $tag));
check('with an adapter the checkbox is not disabled', $tag !== null && !str_contains($tag, ' disabled'));
check('with an adapter the "recommended" sentence is shown', str_contains($with, htmlspecialchars($en['TEXT_PATCH_BACKUP_RECOMMENDED'])));

// A host that does not pass the flag keeps the old behaviour.
$legacy = renderModal([]);
$tag    = checkboxTag($legacy);
check('without the flag the old behaviour is kept (checked, enabled)', $tag !== null && (bool)preg_match('/\schecked(\s|=|>)/', $tag) && !str_contains($tag, ' disabled'));

// Locale parity.
check('the new key exists in both languages', isset($en['TEXT_PATCH_BACKUP_NOT_AVAILABLE'], $hu['TEXT_PATCH_BACKUP_NOT_AVAILABLE']));

echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
