<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Monthly VAT summary view — data loaded via AJAX from AdminActions::vatReport().
 */

declare(strict_types=1);
// Host app must provide: int $companyId, callable $t, string $ajaxUrl
?>
<div class="nav-sync-vat-report"
     data-company-id="<?= (int)$companyId ?>"
     data-ajax-url="<?= htmlspecialchars($ajaxUrl) ?>"
     data-i18n-total="<?= htmlspecialchars($t('TEXT_NAV_TOTAL')) ?>"
     data-i18n-payable="<?= htmlspecialchars($t('TEXT_NAV_PAYABLE_VAT')) ?>"
     data-i18n-cov-ok="<?= htmlspecialchars($t('TEXT_NAV_VAT_COVERAGE_OK')) ?>"
     data-i18n-cov-partial="<?= htmlspecialchars($t('TEXT_NAV_VAT_COVERAGE_PARTIAL')) ?>"
     data-i18n-cov-none="<?= htmlspecialchars($t('TEXT_NAV_VAT_COVERAGE_NONE')) ?>"
     data-i18n-cov-nodate="<?= htmlspecialchars($t('TEXT_NAV_VAT_COVERAGE_NO_DATE')) ?>"
     data-i18n-cov-novat="<?= htmlspecialchars($t('TEXT_NAV_VAT_COVERAGE_NO_VAT')) ?>">

    <div class="nav-vat-toolbar">
        <input type="month" class="nav-vat-month" value="<?= date('Y-m') ?>">
        <small class="nav-vat-note"><?= htmlspecialchars($t('TEXT_NAV_HUF_NOTE')) ?></small>
    </div>

    <div class="nav-vat-coverage" role="status"></div>

    <div class="nav-vat-table-wrap">
        <table class="nav-sync-table nav-vat-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($t('TEXT_NAV_VAT_RATE')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_OUTBOUND_BASE')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_OUTBOUND_VAT')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_INBOUND_BASE')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_INBOUND_VAT')) ?></th>
                </tr>
            </thead>
            <tbody class="nav-vat-tbody"></tbody>
            <tfoot class="nav-vat-tfoot"></tfoot>
        </table>
    </div>

    <div class="nav-vat-payable"></div>
</div>
