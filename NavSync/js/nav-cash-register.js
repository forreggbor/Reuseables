/**
 * NavSync cash register screen: registers, files needing attention, receipts with filters and paging, receipt lines.
 * Needs nav-sync.js loaded first (it publishes the shared helpers on window.NavSyncUi).
 * Every visible string comes from the data-i18n-* attributes of the view; values from the server are only ever set as text.
 */
(function () {
    'use strict';

    const wrap = document.querySelector('.nav-sync-cash');
    if (!wrap) return;

    const ui = window.NavSyncUi;
    if (!ui) {
        console.error('NavSync: nav-cash-register.js needs nav-sync.js to be loaded before it (window.NavSyncUi is missing).');
        return;
    }
    const { number, fill, request } = ui;

    const ajaxUrl   = wrap.dataset.ajaxUrl;
    const companyId = parseInt(wrap.dataset.companyId, 10);
    const limit     = 50;
    const d         = wrap.dataset;
    const q         = sel => wrap.querySelector(sel);

    const state = { offset: 0, total: 0, selectedReceipt: null, message: '', lastSyncText: '', receiptSeq: 0, linesSeq: 0 };

    /** Kind of a receipt (RECEIPT, SIMPLIFIED_INVOICE) to its translated label. */
    const kindLabel = kind => ({ RECEIPT: d.i18nKindReceipt, SIMPLIFIED_INVOICE: d.i18nKindSimplifiedInvoice })[kind] ?? kind;

    /** NAV validation error code (e.g. XML_ERROR) to its translated label; an unknown code is shown as it came. */
    const validationErrorLabel = code => d['i18nErr' + String(code).toLowerCase().replace(/(^|_)([a-z])/g, (m, a, b) => b.toUpperCase())] ?? code;

    /** Times are stored in UTC; shown in Budapest time. */
    function utcTime(value) {
        if (!value) return '–';
        return new Date(String(value).replace(' ', 'T') + 'Z').toLocaleString('hu-HU', { timeZone: 'Europe/Budapest' });
    }

    function cell(text, className = '') {
        const td = document.createElement('td');
        td.textContent = text;
        if (className) td.className = className;
        return td;
    }

    function badge(text, className) {
        const span = document.createElement('span');
        span.className = 'nav-badge ' + className;
        span.textContent = text;
        return span;
    }

    function row(...cells) {
        const tr = document.createElement('tr');
        tr.append(...cells);
        return tr;
    }

    function showMessage(text) {
        state.message = text;
        renderStatusLine();
    }

    function renderStatusLine() {
        q('.nav-cash-last-sync').textContent = state.message || state.lastSyncText;
    }

    /** Translated text of a known error code from the server; anything else gets the given general text (the code goes to the console). */
    function errorText(code, general) {
        const known = { not_enabled: d.i18nNotEnabled, sync_running: d.i18nSyncRunningOther }[code];
        if (!known) console.error('NavSync cash register: server answered with', code);
        return known ?? general;
    }

    /** A failed request is shown to the user and logged to the console; it is never swallowed. */
    function failed(error) {
        console.error('NavSync cash register request failed', error);
        showMessage(d.i18nLoadFailed);
    }

    // ---- registers and files -------------------------------------------------------------------------------------

    function renderRegisters(registers) {
        const body = q('.nav-cash-registers-body');
        body.replaceChildren(...registers.map(r => {
            const status = document.createElement('td');
            if (r.unsynced) status.append(badge(d.i18nUnsynced, 'nav-badge-overdue'));
            const range = Number(r.max_available_file_number) > 0 ? `${r.min_available_file_number}–${r.max_available_file_number}` : '–';
            return row(
                cell(r.ap_number), cell(utcTime(r.last_communication_at)), cell(utcTime(r.last_file_at)),
                cell(range, 'text-right'), cell(String(r.files_total), 'text-right'),
                cell(String(r.files_pending), 'text-right'), cell(String(r.files_error), 'text-right'), status
            );
        }));
        q('.nav-cash-registers-empty').hidden = registers.length > 0;

        // The AP filter offers the company's registers; the current choice survives a reload.
        const select  = q('.nav-cash-filter-ap');
        const current = select.value;
        const all     = select.options[0];
        select.replaceChildren(all, ...registers.map(r => Object.assign(document.createElement('option'), { value: r.ap_number, textContent: r.ap_number })));
        select.value = registers.some(r => r.ap_number === current) ? current : '';
    }

    function renderProblems(files) {
        q('.nav-cash-problems').hidden = files.length === 0;
        q('.nav-cash-problems-body').replaceChildren(...files.map(f => {
            const statusText = { PENDING: d.i18nStatusPending, ERROR: d.i18nStatusError, SKIPPED: d.i18nStatusSkipped }[f.parse_status] ?? f.parse_status;
            const validation = { OK: d.i18nValidationOk, WARN: d.i18nValidationWarn, ERROR: d.i18nValidationError }[f.validation_code] ?? f.validation_code;
            const validationText = f.validation_error ? `${validation}: ${validationErrorLabel(f.validation_error)}` : validation;
            const action = document.createElement('td');
            if (f.validation_code !== 'ERROR') {   // a file NAV rejected is never parsed, so there is nothing to reprocess
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'nav-cash-btn-reparse';
                btn.dataset.fileId = f.id;
                btn.textContent = d.i18nReparse;
                action.append(btn);
            }
            return row(cell(f.ap_number), cell(String(f.file_number), 'text-right'), cell(statusText), cell(validationText), cell(f.parse_error ?? '', 'nav-cash-error-text'), action);
        }));
    }

    function renderLastSync(log) {
        if (!log) { state.lastSyncText = ''; }
        else {
            // started_at is written with the database's own clock (not UTC like NAV's times), so it is shown as it is stored.
            state.lastSyncText = `${d.i18nLastSync}: ${log.started_at}` + (log.error ? ` – ${log.error}` : '');
        }
        renderStatusLine();
    }

    function loadStatus() {
        return request(ajaxUrl, { action: 'nav_cr_status', company_id: companyId })
            .then(data => {
                if (data.error) { failed(data.error); return; }
                renderRegisters(data.registers);
                renderProblems(data.problem_files);
                renderLastSync(data.last_sync);
            })
            .catch(failed);
    }

    // ---- receipts and lines --------------------------------------------------------------------------------------

    function loadReceipts() {
        const seq    = ++state.receiptSeq;
        // The lines on show belong to the previous list; drop them with it.
        state.selectedReceipt = null;
        state.linesSeq++;
        q('.nav-cash-lines').hidden = true;
        const params = { action: 'nav_cr_list', company_id: companyId, limit, offset: state.offset };
        const ap = q('.nav-cash-filter-ap').value, from = q('.nav-cash-filter-from').value, to = q('.nav-cash-filter-to').value;
        if (ap) params.ap = ap;
        if (from) params.dateFrom = from;
        if (to) params.dateTo = to;

        return request(ajaxUrl, params)
            .then(data => {
                if (seq !== state.receiptSeq) return;   // a newer request has been started meanwhile
                if (data.error) { failed(data.error); return; }
                state.total = data.total;
                renderReceipts(data.rows);
            })
            .catch(failed);
    }

    function renderReceipts(rows) {
        q('.nav-cash-receipts-body').replaceChildren(...rows.map(r => {
            const flags = document.createElement('td');
            if (Number(r.practice)) flags.append(badge(d.i18nPractice, 'nav-badge-unpaid'));
            if (Number(r.interrupted)) flags.append(badge(d.i18nInterrupted, 'nav-badge-overdue'));
            const tr = row(cell(r.receipt_number), cell(r.ap_number), cell(r.closed_at), cell(kindLabel(r.kind)), cell(`${number(r.gross_total, 0, 0)} Ft`, 'text-right'), flags);
            tr.className = 'nav-sync-row';
            tr.tabIndex = 0;
            tr.dataset.receiptId = r.id;
            if (String(state.selectedReceipt) === String(r.id)) tr.classList.add('nav-cash-selected');
            const open = () => showLines(r, tr);
            tr.addEventListener('click', open);
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') open(); });
            return tr;
        }));
        q('.nav-cash-receipts-empty').hidden = rows.length > 0;

        const pager = q('.nav-cash-pager');
        pager.hidden = state.total <= limit;
        q('.nav-cash-prev').disabled = state.offset === 0;
        q('.nav-cash-next').disabled = state.offset + limit >= state.total;
        q('.nav-cash-page-info').textContent = state.total === 0 ? '' : fill(d.i18nPage, { from: state.offset + 1, to: Math.min(state.offset + limit, state.total), total: state.total });
    }

    function showLines(receipt, tr) {
        const seq = ++state.linesSeq;
        state.selectedReceipt = receipt.id;
        wrap.querySelectorAll('.nav-cash-receipts-body tr').forEach(r => r.classList.toggle('nav-cash-selected', r === tr));
        q('.nav-cash-lines').hidden = false;
        q('.nav-cash-lines-title').textContent = receipt.receipt_number;
        q('.nav-cash-lines-body').replaceChildren(row(cell(d.i18nLoading)));

        request(ajaxUrl, { action: 'nav_cr_lines', company_id: companyId, receipt_id: receipt.id })
            .then(data => {
                if (seq !== state.linesSeq) return;
                if (data.error) { failed(data.error); return; }
                q('.nav-cash-lines-body').replaceChildren(...data.lines.map(l => {
                    const vat = document.createElement('td');
                    vat.className = 'text-center';
                    if (l.vat_rate === null || l.vat_rate === undefined || l.vat_rate === '') {
                        vat.append(badge(d.i18nUnmapped, 'nav-badge-overdue'));
                    } else {
                        vat.textContent = isFinite(parseFloat(l.vat_rate)) ? `${parseFloat(l.vat_rate)}%` : l.vat_rate;
                    }
                    return row(
                        cell(l.description), cell(l.quantity == null ? '–' : number(l.quantity, 0, 4), 'text-right'),
                        cell(l.unit_price == null ? '–' : `${number(l.unit_price, 0, 2)} Ft`, 'text-right'), vat, cell(`${number(l.gross_amount, 0, 0)} Ft`, 'text-right')
                    );
                }));
            })
            .catch(failed);
    }

    // ---- actions -------------------------------------------------------------------------------------------------

    function triggerSync() {
        const btn = q('.nav-cash-btn-sync');
        btn.disabled = true;
        showMessage(d.i18nSyncRunning);
        request(ajaxUrl, { action: 'nav_cr_sync', company_id: companyId }, 'POST')
            .then(data => {
                // textContent only: NAV's error text is data, never markup.
                if (data.error) showMessage(errorText(data.error, d.i18nSyncFailed));
                else if (data.result.errors.length) { console.error('NavSync cash register sync errors', data.result.errors); showMessage(d.i18nSyncFailed); }
                else if (!data.complete) showMessage(d.i18nSyncIncomplete);
                else showMessage(fill(d.i18nSyncDone, { files: data.result.files, receipts: data.result.receipts }));
                return Promise.all([loadStatus(), loadReceipts()]);
            })
            .catch(failed)
            .finally(() => { btn.disabled = false; });
    }

    function reparse(btn) {
        btn.disabled = true;
        request(ajaxUrl, { action: 'nav_cr_reparse', company_id: companyId, file_id: btn.dataset.fileId }, 'POST')
            .then(data => {
                if (data.error) showMessage(errorText(data.error, d.i18nReparseFailed));
                else if (!data.success) showMessage(d.i18nReparseFailed);
                else showMessage(d.i18nReparseDone);
                return Promise.all([loadStatus(), loadReceipts()]);
            })
            .catch(failed);   // the button is gone after a reload; on a failed reload it stays disabled so a retry is a conscious reload
    }

    // ---- wiring --------------------------------------------------------------------------------------------------

    q('.nav-cash-btn-sync').addEventListener('click', triggerSync);
    q('.nav-cash-problems-body').addEventListener('click', e => {
        const btn = e.target.closest('.nav-cash-btn-reparse');
        if (btn) reparse(btn);
    });
    ['.nav-cash-filter-ap', '.nav-cash-filter-from', '.nav-cash-filter-to'].forEach(sel => q(sel).addEventListener('change', () => { state.offset = 0; loadReceipts(); }));
    q('.nav-cash-prev').addEventListener('click', () => { state.offset = Math.max(0, state.offset - limit); loadReceipts(); });
    q('.nav-cash-next').addEventListener('click', () => { state.offset += limit; loadReceipts(); });

    loadStatus();
    loadReceipts();
})();
