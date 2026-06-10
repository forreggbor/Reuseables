<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Admin page view for LicenseModule — rendered by LicenseModule::renderAdminPage().
 *
 * Available variables (injected by the facade):
 *   $license           — raw DB row array or null
 *   $status            — current license status string
 *   $tier              — array {slug, name, level, description} or null
 *   $addons            — array of addon rows {feature_key, name, slug, description}
 *   $tierModules       — array of module slugs enabled by tier
 *   $history           — array of validation history rows
 *   $daysRemaining     — int|null
 *   $graceDaysRemaining — int|null
 *   $options           — full options array
 *   $t                 — translation closure
 */

$assetBase        = rtrim($options['asset_base_url'] ?? '', '/');
$validateUrl      = $options['validate_url'] ?? null;
$csrfToken        = $options['csrf_token'] ?? '';
$renewUrl         = $options['renew_url'] ?? 'https://lm.patrikmol.com';
$moduleNames      = $options['module_names'] ?? [];
$dateFormat       = $options['date_format'] ?? 'Y-m-d';
$datetimeFormat   = $options['datetime_format'] ?? 'Y-m-d H:i:s';

$showRenew = $status === 'expired' || ($daysRemaining !== null && $daysRemaining <= 30);

$isLegacy = $tier === null && $addons === [];

$te = fn(string $key, mixed ...$params): string =>
    htmlspecialchars($t($key, ...$params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$statusText = match ($status) {
    'active'    => $te('TEXT_STATUS_ACTIVE'),
    'grace'     => $te('TEXT_STATUS_GRACE'),
    'expired'   => $te('TEXT_STATUS_EXPIRED'),
    'suspended' => $te('TEXT_STATUS_SUSPENDED'),
    'invalid'   => $te('TEXT_STATUS_INVALID'),
    'throttled' => $te('TEXT_STATUS_THROTTLED'),
    default     => htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
};
?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/license-admin.css">

<div class="lm-admin-page">

    <div class="lm-header">
        <h2><?= $te('TEXT_NAV_LICENSE_MANAGEMENT') ?></h2>
        <div class="lm-header-actions">
            <?php if ($showRenew): ?>
                <a href="<?= htmlspecialchars($renewUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="lm-btn lm-btn-warning lm-btn-sm">
                    <?= $te('TEXT_BUTTON_RENEW_LICENSE') ?>
                </a>
            <?php endif ?>
            <?php if ($validateUrl !== null): ?>
                <button id="lm-validate-btn"
                        class="lm-btn lm-btn-primary lm-btn-sm"
                        type="button"
                        data-url="<?= htmlspecialchars($validateUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-msg-success="<?= htmlspecialchars($t('TEXT_MESSAGE_VALIDATION_SUCCESS'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-msg-error="<?= htmlspecialchars($t('TEXT_ERROR_VALIDATION_FAILED'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-msg-network="<?= htmlspecialchars($t('TEXT_ERROR_VALIDATION_FAILED'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?= $te('TEXT_BUTTON_VALIDATE_NOW') ?>
                </button>
            <?php endif ?>
        </div>
    </div>

    <div id="lm-alert-container"></div>

    <?php if ($license === null): ?>

        <div class="lm-alert lm-alert-warning">
            <?= $te('TEXT_MESSAGE_NO_LICENSE') ?>
        </div>

    <?php else: ?>

        <!-- Status card -->
        <div class="lm-card lm-status-card lm-status-<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="lm-card-body">
                <div class="lm-status-icon">
                    <?php if ($status === 'active'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M9 12l2 2 4-4"/>
                        </svg>
                    <?php elseif ($status === 'grace'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
                            <path d="M5 3H19"/>
                            <path d="M5 21H19"/>
                            <path d="M5 3l7 9-7 9"/>
                            <path d="M19 3l-7 9 7 9"/>
                        </svg>
                    <?php elseif ($status === 'expired'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M15 9l-6 6"/>
                            <path d="M9 9l6 6"/>
                        </svg>
                    <?php elseif ($status === 'suspended'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    <?php elseif ($status === 'invalid'): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 8v4"/>
                            <path d="M12 16h.01"/>
                        </svg>
                    <?php else: /* throttled */ ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
                            <path d="M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z"/>
                            <line x1="9" y1="15" x2="9" y2="18"/>
                            <line x1="12" y1="13" x2="12" y2="18"/>
                            <line x1="15" y1="15" x2="15" y2="18"/>
                        </svg>
                    <?php endif ?>
                </div>
                <div class="lm-status-body">
                    <div class="lm-badge lm-badge-<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= $statusText ?>
                    </div>
                    <?php if ($status === 'active' && $daysRemaining !== null && $daysRemaining > 30): ?>
                        <p><?= $te('TEXT_MESSAGE_LICENSE_VALID_FOR_DAYS', $daysRemaining) ?></p>
                    <?php elseif ($status === 'active' && $daysRemaining !== null && $daysRemaining <= 30): ?>
                        <p><?= $te('TEXT_MESSAGE_LICENSE_EXPIRES_SOON', $daysRemaining) ?></p>
                    <?php elseif ($status === 'grace' && $graceDaysRemaining !== null): ?>
                        <p><?= $te('TEXT_MESSAGE_GRACE_EXPIRES_IN_DAYS', $graceDaysRemaining) ?></p>
                    <?php elseif ($status === 'expired'): ?>
                        <p><?= $te('TEXT_MESSAGE_LICENSE_EXPIRED') ?></p>
                    <?php elseif ($status === 'suspended'): ?>
                        <p><?= $te('TEXT_MESSAGE_LICENSE_SUSPENDED') ?></p>
                    <?php elseif ($status === 'invalid'): ?>
                        <p><?= $te('TEXT_MESSAGE_LICENSE_INVALID') ?></p>
                    <?php endif ?>
                </div>
            </div>
        </div>

        <!-- Two-column row: License Details + License Features -->
        <div class="lm-row">

            <!-- License Details -->
            <div class="lm-col lm-card">
                <div class="lm-card-header"><?= $te('TEXT_HEADING_LICENSE_DETAILS') ?></div>
                <div class="lm-card-body">
                    <table class="lm-table">
                        <tbody>
                            <?php
                            $key = $license['license_key'] ?? '';
                            $maskedKey = strlen($key) >= 12
                                ? htmlspecialchars(substr($key, 0, 8), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '-****-****-' . htmlspecialchars(substr($key, -4), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                                : htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                            ?>
                            <tr>
                                <td><?= $te('TEXT_LABEL_LICENSE_KEY') ?></td>
                                <td><code><?= $maskedKey ?></code></td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_LICENSE_TYPE') ?></td>
                                <td><?= htmlspecialchars($license['license_type'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_LICENSED_DOMAIN') ?></td>
                                <td><?= htmlspecialchars($license['domain'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_STATUS') ?></td>
                                <td>
                                    <span class="lm-badge lm-badge-<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                        <?= $statusText ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_VALIDATED_AT') ?></td>
                                <td>
                                    <?php if (!empty($license['validated_at'])): ?>
                                        <?= htmlspecialchars(date($datetimeFormat, strtotime($license['validated_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    <?php else: ?>
                                        <?= $te('TEXT_MESSAGE_NEVER') ?>
                                    <?php endif ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_EXPIRES_AT') ?></td>
                                <td>
                                    <?php if (!empty($license['expires_at'])): ?>
                                        <?= htmlspecialchars(date($dateFormat, strtotime($license['expires_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php if ($daysRemaining !== null): ?>
                                            <span class="lm-text-muted">(<?= $te('TEXT_MESSAGE_DAYS_REMAINING', $daysRemaining) ?>)</span>
                                        <?php endif ?>
                                    <?php else: ?>
                                        <?= $te('TEXT_MESSAGE_NEVER') ?>
                                    <?php endif ?>
                                </td>
                            </tr>
                            <?php if ($status === 'grace' && !empty($license['grace_until'])): ?>
                                <tr>
                                    <td><?= $te('TEXT_LABEL_GRACE_EXPIRES_AT') ?></td>
                                    <td>
                                        <?= htmlspecialchars(date($dateFormat, strtotime($license['grace_until'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php if ($graceDaysRemaining !== null): ?>
                                            <span class="lm-text-muted">(<?= $te('TEXT_MESSAGE_DAYS_REMAINING', $graceDaysRemaining) ?>)</span>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endif ?>
                            <tr>
                                <td><?= $te('TEXT_LABEL_LAST_CHECK') ?></td>
                                <td>
                                    <?php if (!empty($license['last_checked_at'])): ?>
                                        <?= htmlspecialchars(date($datetimeFormat, strtotime($license['last_checked_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    <?php else: ?>
                                        <?= $te('TEXT_MESSAGE_NEVER') ?>
                                    <?php endif ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_VALIDATION_FREQUENCY') ?></td>
                                <td>
                                    <?php
                                    $freq = $options['validation_interval_hours'] ?? null;
                                    if ($freq !== null):
                                    ?>
                                        <?= htmlspecialchars((string)$freq, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $te('TEXT_MISC_HOURS') ?>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif ?>
                                </td>
                            </tr>
                            <tr>
                                <td><?= $te('TEXT_LABEL_GRACE_PERIOD') ?></td>
                                <td>
                                    <?php
                                    $grace = $options['grace_period_days'] ?? null;
                                    if ($grace !== null):
                                    ?>
                                        <?= htmlspecialchars((string)$grace, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $te('TEXT_MISC_DAYS') ?>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- License Features -->
            <div class="lm-col lm-card">
                <div class="lm-card-header"><?= $te('TEXT_HEADING_LICENSE_FEATURES') ?></div>
                <div class="lm-card-body">
                    <?php if ($tier !== null): ?>
                        <p>
                            <strong><?= $te('TEXT_LABEL_LICENSE_TIER') ?>:</strong>
                            <span class="lm-badge lm-badge-active">
                                <?= htmlspecialchars($tier['name'] ?? $tier['slug'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </span>
                        </p>
                        <?php if (!empty($tier['description'])): ?>
                            <p class="lm-text-muted"><?= htmlspecialchars($tier['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php endif ?>
                        <?php if (!empty($tierModules)): ?>
                            <p><strong><?= $te('TEXT_LABEL_INCLUDED_MODULES') ?>:</strong></p>
                            <ul class="lm-module-list">
                                <?php foreach ($tierModules as $moduleSlug): ?>
                                    <li>
                                        <?php
                                        $displayName = $moduleNames[$moduleSlug] ?? null;
                                        if ($displayName !== null):
                                        ?>
                                            <?= htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($moduleSlug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php endif ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>
                    <?php elseif ($isLegacy): ?>
                        <p><?= $te('TEXT_MESSAGE_ALL_FEATURES_ENABLED') ?></p>
                    <?php else: ?>
                        <p class="lm-text-muted"><?= $te('TEXT_MESSAGE_NO_TIER') ?></p>
                    <?php endif ?>

                    <hr class="lm-divider">

                    <p><strong><?= $te('TEXT_LABEL_LICENSE_ADDONS') ?></strong></p>
                    <?php if (empty($addons)): ?>
                        <p class="lm-text-muted"><?= $te('TEXT_MESSAGE_NO_ADDONS') ?></p>
                    <?php else: ?>
                        <div class="lm-addon-badges">
                            <?php
                            $addonIndex = 0;
                            foreach ($addons as $addon):
                                $addonName = htmlspecialchars($addon['name'] ?? $addon['feature_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                if (!empty($addon['description'])):
                            ?>
                                    <button class="lm-addon-badge lm-addon-has-desc"
                                            type="button"
                                            data-desc-target="lm-addon-desc-<?= $addonIndex ?>"
                                            aria-expanded="false">
                                        <?= $addonName ?>
                                    </button>
                                <?php else: ?>
                                    <span class="lm-addon-badge"><?= $addonName ?></span>
                            <?php
                                endif;
                                $addonIndex++;
                            endforeach ?>
                        </div>
                        <?php
                        $addonIndex = 0;
                        foreach ($addons as $addon):
                            if (!empty($addon['description'])):
                        ?>
                            <div id="lm-addon-desc-<?= $addonIndex ?>"
                                 class="lm-addon-desc"
                                 hidden>
                                <?= htmlspecialchars($addon['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </div>
                        <?php
                            endif;
                            $addonIndex++;
                        endforeach ?>
                        <div class="lm-addon-descriptions"></div>
                    <?php endif ?>
                </div>
            </div>

        </div><!-- .lm-row -->

        <!-- Validation History -->
        <?php if (!empty($history)): ?>
            <div class="lm-card">
                <div class="lm-card-header"><?= $te('TEXT_HEADING_VALIDATION_HISTORY') ?></div>
                <div class="lm-card-body">
                    <table class="lm-table lm-table-hover">
                        <thead>
                            <tr>
                                <th><?= $te('TEXT_HEADING_DATE_TIME') ?></th>
                                <th><?= $te('TEXT_LABEL_STATUS') ?></th>
                                <th><?= $te('TEXT_HEADING_RESPONSE') ?></th>
                                <th><?= $te('TEXT_HEADING_ERROR_MESSAGE') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($row['validation_time'])): ?>
                                            <?= htmlspecialchars(date($datetimeFormat, strtotime($row['validation_time'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif ?>
                                    </td>
                                    <td>
                                        <span class="lm-badge lm-badge-<?= htmlspecialchars($row['status'] ?? 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['response_data'])): ?>
                                            <span class="lm-truncate" title="<?= htmlspecialchars($row['response_data'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?= htmlspecialchars(mb_substr($row['response_data'], 0, 100), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </span>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['error_message'])): ?>
                                            <?= htmlspecialchars($row['error_message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif ?>

    <?php endif ?>

</div><!-- .lm-admin-page -->

<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/license-admin.js" defer></script>
