<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Printer-friendly activity log view.
 *
 * When asset_base_url is configured, this page loads the module CSS and JS and
 * auto-triggers window.print() via activity-logs.js. Without it the page renders
 * unstyled and does not auto-print (CSP-strict by default — no inline script).
 *
 * Variables expected (passed via extract($data)):
 *   $tr           (callable) — translator
 *   $baseUrl      (string)   — base URL
 *   $assetBaseUrl (string)   — asset base URL (empty string when not configured)
 *   $filters      (array)    — active filters (for display reference)
 *   $resolvedRows (array)    — augmented rows
 *   $generatedAt  (string)   — formatted generation timestamp
 *   $truncated    (bool)     — true when the row cap was hit and rows were trimmed
 */

/** @var callable $tr */
/** @var string   $baseUrl */
/** @var string   $assetBaseUrl */
/** @var array    $filters */
/** @var array    $resolvedRows */
/** @var string   $generatedAt */
/** @var bool     $truncated */

$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $e($tr('TEXT_PRINT_TITLE')) ?></title>
    <?php if ($assetBaseUrl !== ''): ?>
    <link rel="stylesheet" href="<?= $e($assetBaseUrl) ?>activity-logs.css">
    <?php endif; ?>
</head>
<body>
<div class="al-print-root">
    <div class="al-print-header">
        <h1><?= $e($tr('TEXT_PRINT_TITLE')) ?></h1>
        <p class="al-print-meta"><?= $e($tr('TEXT_PRINT_GENERATED', $generatedAt)) ?></p>
    </div>

    <?php if ($truncated): ?>
    <p class="al-print-truncated"><?= $e($tr('TEXT_PRINT_TRUNCATED', number_format(count($resolvedRows)))) ?></p>
    <?php endif; ?>

    <?php if (empty($resolvedRows)): ?>
    <p><?= $e($tr('TEXT_EMPTY_STATE')) ?></p>
    <?php else: ?>
    <table class="al-print-table">
        <thead>
            <tr>
                <th><?= $e($tr('TEXT_TABLE_TIME')) ?></th>
                <th><?= $e($tr('TEXT_TABLE_USER')) ?></th>
                <th><?= $e($tr('TEXT_TABLE_ACTION')) ?></th>
                <th><?= $e($tr('TEXT_TABLE_ENTITY')) ?></th>
                <th><?= $e($tr('TEXT_TABLE_SOURCE')) ?></th>
                <th><?= $e($tr('TEXT_TABLE_IP')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resolvedRows as $row): ?>
            <tr>
                <td><?= $e($row['created_at']) ?></td>
                <td><?= $e($row['user_display']) ?></td>
                <td><?= $e($row['action']) ?></td>
                <td><?= $e($row['entity_name'] ?? '') ?></td>
                <td><?= $e($row['source'] ?? '') ?></td>
                <td><?= $e($row['ip_address'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php if ($assetBaseUrl !== ''): ?>
<script src="<?= $e($assetBaseUrl) ?>activity-logs.js"></script>
<?php endif; ?>
</body>
</html>
