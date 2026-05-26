<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Pagination partial — previous/next links with entry-count summary.
 *
 * Variables expected from parent scope:
 *   $tr         (callable) — translator
 *   $baseUrl    (string)   — base URL for page links
 *   $filters    (array)    — active filters (excluded from page param, re-added here)
 *   $page       (int)      — current page
 *   $pageSize   (int)      — entries per page
 *   $total      (int)      — total matching entries
 *   $totalPages (int)      — total page count
 */

/** @var callable $tr */
/** @var string   $baseUrl */
/** @var array    $filters */
/** @var int      $page */
/** @var int      $pageSize */
/** @var int      $total */
/** @var int      $totalPages */

if ($total === 0) {
    return;
}

$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$firstEntry = (($page - 1) * $pageSize) + 1;
$lastEntry  = min($page * $pageSize, $total);

$filterParams = $filters;
unset($filterParams['page'], $filterParams['action']); // 'action' is ActivityLogger's query key; URLs use 'log_action'
$filterQs = http_build_query(array_merge(['action' => 'index'], $filterParams));

$prevUrl = ($page > 1)
    ? $baseUrl . '?' . $filterQs . '&page=' . ($page - 1)
    : null;
$nextUrl = ($page < $totalPages)
    ? $baseUrl . '?' . $filterQs . '&page=' . ($page + 1)
    : null;
?>
<div class="al-pagination">
    <div class="al-pagination-info">
        <?= $e($tr('TEXT_PAGINATION_SHOWING', $firstEntry, $lastEntry, $total)) ?>
    </div>
    <div class="al-pagination-nav">
        <?php if ($prevUrl !== null): ?>
        <a href="<?= $e($prevUrl) ?>" class="al-btn al-btn-secondary al-btn-sm">&laquo; <?= $e($tr('TEXT_PAGINATION_PREVIOUS')) ?></a>
        <?php else: ?>
        <span class="al-btn al-btn-secondary al-btn-sm al-btn-disabled">&laquo; <?= $e($tr('TEXT_PAGINATION_PREVIOUS')) ?></span>
        <?php endif; ?>

        <span class="al-pagination-pages"><?= $page ?> / <?= $totalPages ?></span>

        <?php if ($nextUrl !== null): ?>
        <a href="<?= $e($nextUrl) ?>" class="al-btn al-btn-secondary al-btn-sm"><?= $e($tr('TEXT_PAGINATION_NEXT')) ?> &raquo;</a>
        <?php else: ?>
        <span class="al-btn al-btn-secondary al-btn-sm al-btn-disabled"><?= $e($tr('TEXT_PAGINATION_NEXT')) ?> &raquo;</span>
        <?php endif; ?>
    </div>
</div>
