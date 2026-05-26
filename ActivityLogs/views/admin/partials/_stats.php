<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Stat cards partial — 6 summary cards showing filtered aggregate counts.
 *
 * Variables expected from parent scope:
 *   $tr    (callable) — translator
 *   $stats (array)    — keys: total, today, this_week, unique_users, unique_actions, unique_entity_types
 */

/** @var callable $tr */
/** @var array    $stats */
?>
<div class="al-stats-grid">
    <div class="al-stat-card al-stat-card-total">
        <div class="al-stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
        <div class="al-stat-label"><?= htmlspecialchars($tr('TEXT_STAT_TOTAL'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="al-stat-card al-stat-card-today">
        <div class="al-stat-value"><?= (int)($stats['today'] ?? 0) ?></div>
        <div class="al-stat-label"><?= htmlspecialchars($tr('TEXT_STAT_TODAY'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="al-stat-card al-stat-card-week">
        <div class="al-stat-value"><?= (int)($stats['this_week'] ?? 0) ?></div>
        <div class="al-stat-label"><?= htmlspecialchars($tr('TEXT_STAT_THIS_WEEK'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="al-stat-card al-stat-card-users">
        <div class="al-stat-value"><?= (int)($stats['unique_users'] ?? 0) ?></div>
        <div class="al-stat-label"><?= htmlspecialchars($tr('TEXT_STAT_ACTIVE_USERS'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="al-stat-card al-stat-card-actions">
        <div class="al-stat-value"><?= (int)($stats['unique_actions'] ?? 0) ?></div>
        <div class="al-stat-label"><?= htmlspecialchars($tr('TEXT_STAT_ACTION_TYPES'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="al-stat-card al-stat-card-entities">
        <div class="al-stat-value"><?= (int)($stats['unique_entity_types'] ?? 0) ?></div>
        <div class="al-stat-label"><?= htmlspecialchars($tr('TEXT_STAT_ENTITY_TYPES'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
</div>
