<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Invoice list view — renders the inbound/outbound tabs shell; data loads via AJAX.
 */

declare(strict_types=1);
// Host app must provide: int $companyId, callable $t, string $ajaxUrl
// Host app may provide: array<string,string> $unitLabels (normalised unit alias => label, so the line tables show a unit as the host names it)
?>
<div class="nav-sync-invoices"
     data-company-id="<?= (int)$companyId ?>"
     data-unit-labels="<?= htmlspecialchars((string)json_encode($unitLabels ?? [], JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)) ?>"
     data-i18n-loading="<?= htmlspecialchars($t('TEXT_NAV_LOADING') ?? 'Betöltés...') ?>"
     data-i18n-overdue="<?= htmlspecialchars($t('TEXT_NAV_STATUS_OVERDUE')) ?>"
     data-i18n-mark-paid="<?= htmlspecialchars($t('TEXT_NAV_MARK_PAID')) ?>"
     data-i18n-mark-unpaid="<?= htmlspecialchars($t('TEXT_NAV_MARK_UNPAID')) ?>"
     data-i18n-payment-date="<?= htmlspecialchars($t('TEXT_NAV_PAYMENT_DATE_LABEL')) ?>"
     data-i18n-line-description="<?= htmlspecialchars($t('TEXT_NAV_LINE_DESCRIPTION')) ?>"
     data-i18n-line-quantity="<?= htmlspecialchars($t('TEXT_NAV_LINE_QUANTITY')) ?>"
     data-i18n-line-unit-price="<?= htmlspecialchars($t('TEXT_NAV_LINE_UNIT_PRICE')) ?>"
     data-i18n-line-vat="<?= htmlspecialchars($t('TEXT_NAV_LINE_VAT_RATE')) ?>"
     data-i18n-line-net="<?= htmlspecialchars($t('TEXT_NAV_LINE_NET')) ?>"
     data-i18n-line-gross="<?= htmlspecialchars($t('TEXT_NAV_LINE_GROSS')) ?>"
     data-i18n-sync-running="<?= htmlspecialchars($t('TEXT_NAV_SYNC_RUNNING')) ?>"
     data-i18n-sync-done="<?= htmlspecialchars($t('TEXT_NAV_SYNC_DONE')) ?>"
     data-i18n-sync-incomplete="<?= htmlspecialchars($t('TEXT_NAV_SYNC_INCOMPLETE')) ?>"
     data-i18n-sync-failed="<?= htmlspecialchars($t('TEXT_NAV_SYNC_FAILED')) ?>"
     data-i18n-bank-account="<?= htmlspecialchars($t('TEXT_NAV_BANK_ACCOUNT')) ?>"
     data-i18n-customer-address="<?= htmlspecialchars($t('TEXT_NAV_CUSTOMER_ADDRESS')) ?>"
     data-i18n-type="<?= htmlspecialchars($t('TEXT_NAV_TYPE')) ?>"
     data-i18n-original-invoice="<?= htmlspecialchars($t('TEXT_NAV_ORIGINAL_INVOICE')) ?>"
     data-i18n-kind-invoice="<?= htmlspecialchars($t('TEXT_NAV_KIND_INVOICE')) ?>"
     data-i18n-kind-advance="<?= htmlspecialchars($t('TEXT_NAV_KIND_ADVANCE')) ?>"
     data-i18n-kind-final="<?= htmlspecialchars($t('TEXT_NAV_KIND_FINAL')) ?>"
     data-i18n-kind-correction="<?= htmlspecialchars($t('TEXT_NAV_KIND_CORRECTION')) ?>"
     data-i18n-kind-storno="<?= htmlspecialchars($t('TEXT_NAV_KIND_STORNO')) ?>"
     data-i18n-kind-stornoed="<?= htmlspecialchars($t('TEXT_NAV_KIND_STORNOED')) ?>"
     data-i18n-kind-corrected="<?= htmlspecialchars($t('TEXT_NAV_KIND_CORRECTED')) ?>"
     data-i18n-total-count="<?= htmlspecialchars($t('TEXT_NAV_TOTAL_COUNT')) ?>"
     data-i18n-total-invoices="<?= htmlspecialchars($t('TEXT_NAV_TOTAL_INVOICES')) ?>"
     data-i18n-outstanding="<?= htmlspecialchars($t('TEXT_NAV_OUTSTANDING')) ?>">

    <div class="nav-sync-toolbar">
        <div class="nav-sync-tabs">
            <button class="nav-sync-tab active" data-direction="INBOUND">
                <?= $t('TEXT_NAV_INVOICES_INBOUND') ?>
            </button>
            <button class="nav-sync-tab" data-direction="OUTBOUND">
                <?= $t('TEXT_NAV_INVOICES_OUTBOUND') ?>
            </button>
        </div>
        <div class="nav-sync-filters">
            <input type="text"   class="nav-sync-search"    placeholder="<?= $t('TEXT_NAV_SEARCH_PLACEHOLDER') ?>">
            <input type="date"   class="nav-sync-date-from" placeholder="<?= $t('TEXT_NAV_COMPLETION_DATE') ?> from">
            <input type="date"   class="nav-sync-date-to"   placeholder="<?= $t('TEXT_NAV_COMPLETION_DATE') ?> to">
            <select class="nav-sync-paid-filter">
                <option value=""><?= $t('TEXT_NAV_STATUS_ALL') ?></option>
                <option value="0"><?= $t('TEXT_NAV_STATUS_UNPAID') ?></option>
                <option value="1"><?= $t('TEXT_NAV_STATUS_PAID') ?></option>
                <option value="overdue"><?= $t('TEXT_NAV_STATUS_OVERDUE') ?></option>
            </select>
        </div>
        <button class="nav-sync-btn-sync" data-ajax-url="<?= htmlspecialchars($ajaxUrl) ?>">
            <?= $t('TEXT_NAV_SYNC_NOW') ?>
        </button>
    </div>

    <div class="nav-sync-last-sync"></div>

    <div class="nav-sync-table-wrap">
        <table class="nav-sync-table">
            <thead>
                <tr>
                    <th class="nav-kind-head"><?= $t('TEXT_NAV_TYPE') ?></th>
                    <?php
                    // Every other column is sortable (the kind is derived, not a column); the key is what the JS sends as ?sort= (see InvoiceRepository::list).
                    foreach ([
                        ['invoice_number',  'TEXT_NAV_INVOICE_NUMBER',  ''],
                        ['partner',         'TEXT_NAV_PARTNER',         ''],
                        ['issue_date',      'TEXT_NAV_ISSUE_DATE',      ''],
                        ['completion_date', 'TEXT_NAV_COMPLETION_DATE', ''],
                        ['payment_date',    'TEXT_NAV_PAYMENT_DATE',    ''],
                        ['net',             'TEXT_NAV_NET',             ' text-right'],
                        ['vat',             'TEXT_NAV_VAT',             ' text-right'],
                        ['gross',           'TEXT_NAV_GROSS',           ' text-right'],
                        ['paid',            'TEXT_NAV_STATUS',          ''],
                    ] as [$sortKey, $labelKey, $align]): ?>
                    <th class="nav-sortable<?= $align ?>" data-sort="<?= $sortKey ?>" aria-sort="none">
                        <button type="button" class="nav-sort-btn"><?= $t($labelKey) ?><span class="nav-sort-arrow" aria-hidden="true"></span></button>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="nav-sync-tbody"></tbody>
            <tfoot class="nav-sync-tfoot"></tfoot>
        </table>
        <div class="nav-sync-pagination"></div>
        <div class="nav-sync-empty" hidden><?= $t('TEXT_NAV_NO_RESULTS') ?></div>
    </div>
</div>
