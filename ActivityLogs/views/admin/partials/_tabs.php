<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Source tabs partial — clicking a tab sets the source filter in the URL.
 * The "All" tab clears the source filter.
 *
 * Variables expected from parent scope:
 *   $tr       (callable)      — translator
 *   $baseUrl  (string)        — base URL for filter links
 *   $sources  (array<string>) — unique source values
 *   $filters  (array)         — active filters (reads filters['source'])
 *   $request  (array)         — original request (for rebuilding query string)
 */

/** @var callable $tr */
/** @var string   $baseUrl */
/** @var array    $sources */
/** @var array    $filters */
/** @var array    $request */

if (empty($sources)) {
    return;
}

$e          = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$activeSource = $filters['source'] ?? '';

// Build a query string from current filters, excluding source and page
$alBuildTabUrl = function (string $baseUrl, array $request, string $source): string {
    $params = [];
    foreach ($request as $k => $v) {
        if ($k === 'source' || $k === 'page') {
            continue;
        }
        $params[$k] = $v;
    }
    if ($source !== '') {
        $params['source'] = $source;
    }
    $qs = http_build_query($params);
    return $baseUrl . ($qs !== '' ? '?' . $qs : '');
};
?>
<div class="al-tabs" role="tablist">
    <a href="<?= $e($alBuildTabUrl($baseUrl, $request, '')) ?>"
       class="al-tab<?= $activeSource === '' ? ' al-tab-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSource === '' ? 'true' : 'false' ?>">
        <?= $e($tr('TEXT_FILTER_ALL_SOURCES')) ?>
    </a>
    <?php foreach ($sources as $source): ?>
    <a href="<?= $e($alBuildTabUrl($baseUrl, $request, $source)) ?>"
       class="al-tab<?= $activeSource === $source ? ' al-tab-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSource === $source ? 'true' : 'false' ?>">
        <?= $e($source) ?>
    </a>
    <?php endforeach; ?>
</div>
