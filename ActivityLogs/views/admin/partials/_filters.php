<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Filter bar partial — sticky bar with dropdowns, date range, and text search.
 *
 * Variables expected from parent scope:
 *   $tr          (callable)         — translator
 *   $baseUrl     (string)           — form action URL
 *   $filters     (array)            — active filter values
 *   $userMap     (array<int,string>) — user ID → display name
 *   $actions     (array<string>)    — unique action names
 *   $entityTypes (array<string>)    — unique entity types
 *   $sources     (array<string>)    — unique sources
 */

/** @var callable $tr */
/** @var string   $baseUrl */
/** @var array    $filters */
/** @var array    $userMap */
/** @var array    $actions */
/** @var array    $entityTypes */
/** @var array    $sources */

$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sel = fn(mixed $opt, mixed $current): string => ((string)$opt === (string)$current) ? ' selected' : '';
?>
<form method="get" action="<?= $e($baseUrl) ?>" class="al-filter-bar" id="al-filter-form">
    <input type="hidden" name="action" value="index">

    <div class="al-filter-group">
        <label class="al-filter-label" for="al-filter-user"><?= $e($tr('TEXT_FILTER_USER')) ?></label>
        <select name="user_id" id="al-filter-user" class="al-filter-select">
            <option value=""><?= $e($tr('TEXT_FILTER_ALL_USERS')) ?></option>
            <?php foreach ($userMap as $uid => $name): ?>
            <option value="<?= (int)$uid ?>"<?= $sel($uid, $filters['user_id'] ?? '') ?>><?= $e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="al-filter-group">
        <label class="al-filter-label" for="al-filter-action"><?= $e($tr('TEXT_FILTER_ACTION')) ?></label>
        <select name="log_action" id="al-filter-action" class="al-filter-select">
            <option value=""><?= $e($tr('TEXT_FILTER_ALL_ACTIONS')) ?></option>
            <?php foreach ($actions as $act): ?>
            <option value="<?= $e($act) ?>"<?= $sel($act, $filters['action'] ?? '') ?>><?= $e($act) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="al-filter-group">
        <label class="al-filter-label" for="al-filter-entity-type"><?= $e($tr('TEXT_FILTER_ENTITY_TYPE')) ?></label>
        <select name="entity_type" id="al-filter-entity-type" class="al-filter-select">
            <option value=""><?= $e($tr('TEXT_FILTER_ALL_ENTITY_TYPES')) ?></option>
            <?php foreach ($entityTypes as $et): ?>
            <option value="<?= $e($et) ?>"<?= $sel($et, $filters['entity_type'] ?? '') ?>><?= $e($et) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="al-filter-group">
        <label class="al-filter-label" for="al-filter-source"><?= $e($tr('TEXT_FILTER_SOURCE')) ?></label>
        <select name="source" id="al-filter-source" class="al-filter-select">
            <option value=""><?= $e($tr('TEXT_FILTER_ALL_SOURCES')) ?></option>
            <?php foreach ($sources as $src): ?>
            <option value="<?= $e($src) ?>"<?= $sel($src, $filters['source'] ?? '') ?>><?= $e($src) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="al-filter-group">
        <label class="al-filter-label" for="al-filter-date-from"><?= $e($tr('TEXT_FILTER_DATE_FROM')) ?></label>
        <input type="date" name="date_from" id="al-filter-date-from" class="al-filter-input"
               value="<?= $e($filters['date_from'] ?? '') ?>">
    </div>

    <div class="al-filter-group">
        <label class="al-filter-label" for="al-filter-date-to"><?= $e($tr('TEXT_FILTER_DATE_TO')) ?></label>
        <input type="date" name="date_to" id="al-filter-date-to" class="al-filter-input"
               value="<?= $e($filters['date_to'] ?? '') ?>">
    </div>

    <div class="al-filter-group al-filter-group-search">
        <label class="al-filter-label" for="al-filter-search"><?= $e($tr('TEXT_FILTER_SEARCH')) ?></label>
        <input type="text" name="search" id="al-filter-search" class="al-filter-input"
               placeholder="<?= $e($tr('TEXT_FILTER_SEARCH_PLACEHOLDER')) ?>"
               value="<?= $e($filters['search'] ?? '') ?>">
    </div>

    <div class="al-filter-actions">
        <button type="submit" class="al-btn al-btn-primary"><?= $e($tr('TEXT_BUTTON_FILTER')) ?></button>
        <a href="<?= $e($baseUrl . '?action=index') ?>" class="al-btn al-btn-secondary"><?= $e($tr('TEXT_BUTTON_CLEAR')) ?></a>
    </div>
</form>
