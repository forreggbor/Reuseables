<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Log entries table partial — paginated rows with expandable old/new diff.
 *
 * Variables expected from parent scope:
 *   $tr           (callable) — translator
 *   $resolvedRows (array)    — augmented rows from AdminActions::resolveRows()
 */

/** @var callable $tr */
/** @var array    $resolvedRows */

$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** @param array<string,mixed> $data @return string */
$alRenderKV = function (array $data): string {
    $html = '<dl class="al-kv">';
    foreach ($data as $key => $value) {
        $k = htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $v = htmlspecialchars(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= '<dt class="al-kv-key">' . $k . '</dt><dd class="al-kv-value">' . $v . '</dd>';
    }
    return $html . '</dl>';
};
?>

<?php if (empty($resolvedRows)): ?>
<div class="al-empty-state"><?= $e($tr('TEXT_EMPTY_STATE')) ?></div>
<?php else: ?>
<div class="al-table-wrapper">
    <table class="al-table">
        <thead>
            <tr>
                <th class="al-th al-th-expand"></th>
                <th class="al-th"><?= $e($tr('TEXT_TABLE_TIME')) ?></th>
                <th class="al-th"><?= $e($tr('TEXT_TABLE_USER')) ?></th>
                <th class="al-th"><?= $e($tr('TEXT_TABLE_ACTION')) ?></th>
                <th class="al-th"><?= $e($tr('TEXT_TABLE_ENTITY')) ?></th>
                <th class="al-th"><?= $e($tr('TEXT_TABLE_SOURCE')) ?></th>
                <th class="al-th"><?= $e($tr('TEXT_TABLE_IP')) ?></th>
                <th class="al-th al-th-details"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($resolvedRows as $row): ?>
            <tr class="al-tr" data-log-id="<?= (int)$row['id'] ?>">
                <td class="al-td al-td-expand">
                    <?php if ($row['has_diff']): ?>
                    <button type="button" class="al-expand-btn" aria-expanded="false" aria-label="<?= $e($tr('TEXT_TABLE_DETAILS')) ?>">
                        <span class="al-chevron">&#9658;</span>
                    </button>
                    <?php endif; ?>
                </td>
                <td class="al-td al-td-time"><?= $e($row['created_at']) ?></td>
                <td class="al-td al-td-user"><?= $e($row['user_display']) ?></td>
                <td class="al-td al-td-action">
                    <span class="al-badge <?= $e($row['badge_class']) ?>"><?= $e($row['action']) ?></span>
                </td>
                <td class="al-td al-td-entity">
                    <?php if ($row['entity_name'] !== null): ?>
                    <span class="al-entity-name"><?= $e($row['entity_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="al-td al-td-source">
                    <?php if ($row['source'] !== null): ?>
                    <span class="al-source-badge"><?= $e($row['source']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="al-td al-td-ip"><?= $e($row['ip_address'] ?? '') ?></td>
                <td class="al-td al-td-details">
                    <button type="button" class="al-details-btn" data-log-id="<?= (int)$row['id'] ?>">&#8942;</button>
                </td>
            </tr>
            <?php if ($row['has_diff']): ?>
            <tr class="al-tr-diff" data-diff-for="<?= (int)$row['id'] ?>" hidden>
                <td colspan="8" class="al-td-diff">
                    <div class="al-diff-grid">
                        <?php if ($row['old_values'] !== null): ?>
                        <div class="al-diff-col al-diff-old">
                            <div class="al-diff-heading"><?= $e($tr('TEXT_TABLE_OLD_VALUES')) ?></div>
                            <?= $alRenderKV($row['old_values']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($row['new_values'] !== null): ?>
                        <div class="al-diff-col al-diff-new">
                            <div class="al-diff-heading"><?= $e($tr('TEXT_TABLE_NEW_VALUES')) ?></div>
                            <?= $alRenderKV($row['new_values']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
