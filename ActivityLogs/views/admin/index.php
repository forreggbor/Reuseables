<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Activity log admin index view — 6 stat cards, source tabs, filters, table, pagination.
 *
 * Variables (passed via extract($data)):
 *   $tr           (callable)              — translator callable: fn(string $key, mixed ...$params): string
 *   $baseUrl      (string)               — base URL for action routing
 *   $locale       (string)               — locale code
 *   $filters      (array)                — active filter values
 *   $page         (int)                  — current page number
 *   $pageSize     (int)                  — entries per page
 *   $total        (int)                  — total matching entries
 *   $totalPages   (int)                  — total page count
 *   $stats        (array)                — aggregate stat card values
 *   $actions      (array<string>)        — unique action names for filter dropdown
 *   $entityTypes  (array<string>)        — unique entity types for filter dropdown
 *   $sources      (array<string>)        — unique source values for tabs and filter
 *   $userMap      (array<int,string>)    — user ID → display name map
 *   $resolvedRows (array)                — augmented log rows (see AdminActions::resolveRows)
 *   $request      (array)                — original request parameters
 */

/** @var callable $tr */
/** @var string   $baseUrl */
/** @var string   $locale */
/** @var array    $filters */
/** @var int      $page */
/** @var int      $pageSize */
/** @var int      $total */
/** @var int      $totalPages */
/** @var array    $stats */
/** @var array    $actions */
/** @var array    $entityTypes */
/** @var array    $sources */
/** @var array    $userMap */
/** @var array    $resolvedRows */
/** @var array    $request */

$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$detailUrl  = $baseUrl . '?action=details';
$exportUrl  = $baseUrl . '?action=exportCsv';
$printUrl   = $baseUrl . '?action=printView';

// Preserve current filters in export/print URLs.
// Exclude the 'action' key (ActivityLogger's query key) — 'log_action' carries the same value for URLs.
$filterQuery = '';
foreach ($filters as $k => $v) {
    if ($k === 'action') {
        continue;
    }
    $filterQuery .= '&' . urlencode($k) . '=' . urlencode((string)$v);
}
?>
<!DOCTYPE html>
<html lang="<?= $e($locale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($tr('TEXT_ACTIVITY_LOG_TITLE')) ?></title>
</head>
<body>
<div class="al-root"
     data-al-base-url="<?= $e($baseUrl) ?>"
     data-al-detail-url="<?= $e($detailUrl) ?>"
     data-al-locale="<?= $e($locale) ?>">

    <div class="al-page-header">
        <h1 class="al-page-title"><?= $e($tr('TEXT_ACTIVITY_LOG_TITLE')) ?></h1>
        <div class="al-page-actions">
            <a href="<?= $e($exportUrl . $filterQuery) ?>" class="al-btn al-btn-secondary"><?= $e($tr('TEXT_BUTTON_EXPORT_CSV')) ?></a>
            <button type="button" class="al-btn al-btn-secondary" data-al-print-url="<?= $e($printUrl . $filterQuery) ?>"><?= $e($tr('TEXT_BUTTON_PRINT')) ?></button>
        </div>
    </div>

    <?php include __DIR__ . '/partials/_stats.php'; ?>
    <?php include __DIR__ . '/partials/_tabs.php'; ?>
    <?php include __DIR__ . '/partials/_filters.php'; ?>
    <?php include __DIR__ . '/partials/_table.php'; ?>
    <?php include __DIR__ . '/partials/_pagination.php'; ?>

</div>

<div class="al-modal-overlay" id="al-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="al-modal-title">
    <div class="al-modal">
        <div class="al-modal-header">
            <h2 class="al-modal-title" id="al-modal-title"><?= $e($tr('TEXT_MODAL_TITLE')) ?></h2>
            <button type="button" class="al-modal-close" aria-label="<?= $e($tr('TEXT_MODAL_CLOSE')) ?>">&times;</button>
        </div>
        <div class="al-modal-body" id="al-modal-body">
            <p class="al-modal-loading"><?= $e($tr('TEXT_LOADING')) ?></p>
        </div>
    </div>
</div>
</body>
</html>
