<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Cash register view — registers, files needing attention, receipts and their lines; data loads via AJAX
 * from the nav_cr_* actions of AdminActions (needs nav-sync.js loaded before nav-cash-register.js).
 */

declare(strict_types=1);
// Host app must provide: int $companyId, callable $t, string $ajaxUrl
?>
<div class="nav-sync-cash"
     data-company-id="<?= (int)$companyId ?>"
     data-ajax-url="<?= htmlspecialchars($ajaxUrl) ?>"
     data-i18n-loading="<?= htmlspecialchars($t('TEXT_NAV_LOADING')) ?>"
     data-i18n-load-failed="<?= htmlspecialchars($t('TEXT_NAV_CR_LOAD_FAILED')) ?>"
     data-i18n-last-sync="<?= htmlspecialchars($t('TEXT_NAV_LAST_SYNC')) ?>"
     data-i18n-sync-running="<?= htmlspecialchars($t('TEXT_NAV_SYNC_RUNNING')) ?>"
     data-i18n-sync-failed="<?= htmlspecialchars($t('TEXT_NAV_CR_SYNC_FAILED')) ?>"
     data-i18n-sync-done="<?= htmlspecialchars($t('TEXT_NAV_CR_SYNC_DONE')) ?>"
     data-i18n-sync-incomplete="<?= htmlspecialchars($t('TEXT_NAV_CR_SYNC_INCOMPLETE')) ?>"
     data-i18n-not-enabled="<?= htmlspecialchars($t('TEXT_NAV_CR_NOT_ENABLED')) ?>"
     data-i18n-sync-running-other="<?= htmlspecialchars($t('TEXT_NAV_CR_SYNC_RUNNING')) ?>"
     data-i18n-reparse="<?= htmlspecialchars($t('TEXT_NAV_CR_REPARSE')) ?>"
     data-i18n-reparse-done="<?= htmlspecialchars($t('TEXT_NAV_CR_REPARSE_DONE')) ?>"
     data-i18n-reparse-failed="<?= htmlspecialchars($t('TEXT_NAV_CR_REPARSE_FAILED')) ?>"
     data-i18n-reparse-unmapped="<?= htmlspecialchars($t('TEXT_NAV_CR_REPARSE_UNMAPPED')) ?>"
     data-i18n-reparse-unmapped-done="<?= htmlspecialchars($t('TEXT_NAV_CR_REPARSE_UNMAPPED_DONE')) ?>"
     data-i18n-unsynced="<?= htmlspecialchars($t('TEXT_NAV_CR_UNSYNCED')) ?>"
     data-i18n-unmapped="<?= htmlspecialchars($t('TEXT_NAV_CR_UNMAPPED_LINES')) ?>"
     data-i18n-practice="<?= htmlspecialchars($t('TEXT_NAV_CR_PRACTICE')) ?>"
     data-i18n-interrupted="<?= htmlspecialchars($t('TEXT_NAV_CR_INTERRUPTED')) ?>"
     data-i18n-kind-receipt="<?= htmlspecialchars($t('TEXT_NAV_CR_KIND_RECEIPT')) ?>"
     data-i18n-kind-simplified-invoice="<?= htmlspecialchars($t('TEXT_NAV_CR_KIND_SIMPLIFIED_INVOICE')) ?>"
     data-i18n-page="<?= htmlspecialchars($t('TEXT_NAV_CR_PAGE')) ?>"
     data-i18n-status-pending="<?= htmlspecialchars($t('TEXT_NAV_CR_FILE_PENDING')) ?>"
     data-i18n-status-error="<?= htmlspecialchars($t('TEXT_NAV_CR_PARSE_ERROR')) ?>"
     data-i18n-status-skipped="<?= htmlspecialchars($t('TEXT_NAV_CR_FILE_SKIPPED')) ?>"
     data-i18n-validation-ok="<?= htmlspecialchars($t('TEXT_NAV_CR_VALIDATION_OK')) ?>"
     data-i18n-validation-warn="<?= htmlspecialchars($t('TEXT_NAV_CR_VALIDATION_WARN')) ?>"
     data-i18n-validation-error="<?= htmlspecialchars($t('TEXT_NAV_CR_VALIDATION_ERROR')) ?>"
     data-i18n-err-empty-file="<?= htmlspecialchars($t('TEXT_NAV_CR_ERR_EMPTY_FILE')) ?>"
     data-i18n-err-filename-error="<?= htmlspecialchars($t('TEXT_NAV_CR_ERR_FILENAME_ERROR')) ?>"
     data-i18n-err-signature-error="<?= htmlspecialchars($t('TEXT_NAV_CR_ERR_SIGNATURE_ERROR')) ?>"
     data-i18n-err-value-error="<?= htmlspecialchars($t('TEXT_NAV_CR_ERR_VALUE_ERROR')) ?>"
     data-i18n-err-xml-error="<?= htmlspecialchars($t('TEXT_NAV_CR_ERR_XML_ERROR')) ?>"
     data-i18n-err-xsd-error="<?= htmlspecialchars($t('TEXT_NAV_CR_ERR_XSD_ERROR')) ?>">

    <div class="nav-sync-toolbar">
        <h3 class="nav-cash-title"><?= htmlspecialchars($t('TEXT_NAV_CR_TITLE')) ?></h3>
        <button type="button" class="nav-sync-btn-sync nav-cash-btn-sync"><?= htmlspecialchars($t('TEXT_NAV_CR_SYNC_NOW')) ?></button>
        <button type="button" class="nav-pg-btn nav-cash-btn-reparse-unmapped" hidden></button>
    </div>
    <div class="nav-sync-last-sync nav-cash-last-sync" role="status"></div>

    <h4 class="nav-cash-heading"><?= htmlspecialchars($t('TEXT_NAV_CR_REGISTERS')) ?></h4>
    <div class="nav-sync-table-wrap">
        <table class="nav-sync-table nav-cash-registers">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_AP_NUMBER')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_LAST_COMMUNICATION')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_LAST_FILE')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_CR_FILE_RANGE')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_CR_FILES')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_CR_FILE_PENDING')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_CR_FILE_ERROR')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_STATUS')) ?></th>
                </tr>
            </thead>
            <tbody class="nav-cash-registers-body"></tbody>
        </table>
        <div class="nav-sync-empty nav-cash-registers-empty" hidden><?= htmlspecialchars($t('TEXT_NAV_CR_NO_REGISTERS')) ?></div>
    </div>

    <div class="nav-cash-problems" hidden>
        <h4 class="nav-cash-heading"><?= htmlspecialchars($t('TEXT_NAV_CR_PROBLEM_FILES')) ?></h4>
        <div class="nav-sync-table-wrap">
            <table class="nav-sync-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($t('TEXT_NAV_CR_AP_NUMBER')) ?></th>
                        <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_CR_FILE_NUMBER')) ?></th>
                        <th><?= htmlspecialchars($t('TEXT_NAV_CR_STATUS')) ?></th>
                        <th><?= htmlspecialchars($t('TEXT_NAV_CR_VALIDATION')) ?></th>
                        <th><?= htmlspecialchars($t('TEXT_NAV_CR_PARSE_ERROR')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="nav-cash-problems-body"></tbody>
            </table>
        </div>
    </div>

    <h4 class="nav-cash-heading"><?= htmlspecialchars($t('TEXT_NAV_CR_RECEIPTS')) ?></h4>
    <div class="nav-sync-filters nav-cash-filters">
        <label><span><?= htmlspecialchars($t('TEXT_NAV_CR_AP_NUMBER')) ?></span>
            <select class="nav-cash-filter-ap"><option value=""><?= htmlspecialchars($t('TEXT_NAV_STATUS_ALL')) ?></option></select>
        </label>
        <label><span><?= htmlspecialchars($t('TEXT_NAV_CR_DATE_FROM')) ?></span>
            <input type="date" class="nav-cash-filter-from">
        </label>
        <label><span><?= htmlspecialchars($t('TEXT_NAV_CR_DATE_TO')) ?></span>
            <input type="date" class="nav-cash-filter-to">
        </label>
    </div>
    <div class="nav-sync-table-wrap">
        <table class="nav-sync-table nav-cash-receipts">
            <thead>
                <tr>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_RECEIPT_NUMBER')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_AP_NUMBER')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_CLOSED_AT')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_TYPE')) ?></th>
                    <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_CR_GROSS')) ?></th>
                    <th><?= htmlspecialchars($t('TEXT_NAV_CR_STATUS')) ?></th>
                </tr>
            </thead>
            <tbody class="nav-cash-receipts-body"></tbody>
        </table>
        <div class="nav-sync-empty nav-cash-receipts-empty" hidden><?= htmlspecialchars($t('TEXT_NAV_CR_NO_RECEIPTS')) ?></div>
        <div class="nav-cash-pager" hidden>
            <button type="button" class="nav-pg-btn nav-cash-prev"><?= htmlspecialchars($t('TEXT_NAV_CR_PREVIOUS')) ?></button>
            <span class="nav-cash-page-info"></span>
            <button type="button" class="nav-pg-btn nav-cash-next"><?= htmlspecialchars($t('TEXT_NAV_CR_NEXT')) ?></button>
        </div>
    </div>

    <dialog class="nav-cash-dialog" aria-labelledby="nav-cash-dialog-title">
        <div class="nav-cash-dialog-head">
            <h4 class="nav-cash-heading" id="nav-cash-dialog-title"><?= htmlspecialchars($t('TEXT_NAV_CR_LINES')) ?>: <span class="nav-cash-lines-title"></span></h4>
            <button type="button" class="nav-pg-btn nav-cash-dialog-close"><?= htmlspecialchars($t('TEXT_NAV_CR_CLOSE')) ?></button>
        </div>
        <div class="nav-sync-table-wrap">
            <table class="nav-sync-table nav-lines-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($t('TEXT_NAV_LINE_DESCRIPTION')) ?></th>
                        <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_LINE_QUANTITY')) ?></th>
                        <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_LINE_UNIT_PRICE')) ?></th>
                        <th class="text-center"><?= htmlspecialchars($t('TEXT_NAV_LINE_VAT_RATE')) ?></th>
                        <th class="text-right"><?= htmlspecialchars($t('TEXT_NAV_LINE_GROSS')) ?></th>
                    </tr>
                </thead>
                <tbody class="nav-cash-lines-body"></tbody>
            </table>
        </div>
    </dialog>
</div>
