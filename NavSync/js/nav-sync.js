(function () {
    'use strict';

    const state = {
        companyId:  0,
        direction:  'INBOUND',
        page:       1,
        limit:      50,
        sort:       'issue_date',   // matches the server default: newest issue date first
        dir:        'desc',
        filters:    {},
        ajaxUrl:    '',
        i18n:       {},
        unitLabels: {},
    };

    function init(wrap) {
        state.companyId = parseInt(wrap.dataset.companyId, 10);
        state.ajaxUrl   = wrap.querySelector('.nav-sync-btn-sync').dataset.ajaxUrl;
        try { state.unitLabels = JSON.parse(wrap.dataset.unitLabels || '{}') || {}; } catch (e) { state.unitLabels = {}; }
        state.i18n = {
            loading:       wrap.dataset.i18nLoading,
            overdue:       wrap.dataset.i18nOverdue,
            markPaid:      wrap.dataset.i18nMarkPaid,
            markUnpaid:    wrap.dataset.i18nMarkUnpaid,
            paymentDate:   wrap.dataset.i18nPaymentDate,
            lineDesc:      wrap.dataset.i18nLineDescription,
            lineQty:       wrap.dataset.i18nLineQuantity,
            lineUnitPrice: wrap.dataset.i18nLineUnitPrice,
            lineVat:       wrap.dataset.i18nLineVat,
            lineNet:       wrap.dataset.i18nLineNet,
            lineGross:     wrap.dataset.i18nLineGross,
            syncRunning:   wrap.dataset.i18nSyncRunning,
            syncDone:      wrap.dataset.i18nSyncDone,
            syncIncomplete: wrap.dataset.i18nSyncIncomplete,
            syncFailed:    wrap.dataset.i18nSyncFailed,
            bankAccount:   wrap.dataset.i18nBankAccount,
            customerAddress: wrap.dataset.i18nCustomerAddress,
            type:          wrap.dataset.i18nType,
            originalInvoice: wrap.dataset.i18nOriginalInvoice,
            stornoed:      wrap.dataset.i18nKindStornoed,
            corrected:     wrap.dataset.i18nKindCorrected,
            kinds: {
                INVOICE:    wrap.dataset.i18nKindInvoice,
                ADVANCE:    wrap.dataset.i18nKindAdvance,
                FINAL:      wrap.dataset.i18nKindFinal,
                CORRECTION: wrap.dataset.i18nKindCorrection,
                STORNO:     wrap.dataset.i18nKindStorno,
            },
            totalCount:    wrap.dataset.i18nTotalCount,
            totalInvoices: wrap.dataset.i18nTotalInvoices,
            outstanding:   wrap.dataset.i18nOutstanding,
        };

        wrap.querySelector('.nav-sync-tabs').addEventListener('click', e => {
            const btn = e.target.closest('.nav-sync-tab');
            if (!btn) return;
            wrap.querySelectorAll('.nav-sync-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            state.direction = btn.dataset.direction;
            state.page = 1;
            load(wrap);
        });

        wrap.querySelector('.nav-sync-search').addEventListener('input', debounce(() => {
            state.filters.search = wrap.querySelector('.nav-sync-search').value;
            state.page = 1;
            load(wrap);
        }, 350));

        wrap.querySelector('.nav-sync-date-from').addEventListener('change', () => {
            state.filters.dateFrom = wrap.querySelector('.nav-sync-date-from').value;
            state.page = 1;
            load(wrap);
        });

        wrap.querySelector('.nav-sync-date-to').addEventListener('change', () => {
            state.filters.dateTo = wrap.querySelector('.nav-sync-date-to').value;
            state.page = 1;
            load(wrap);
        });

        wrap.querySelector('.nav-sync-paid-filter').addEventListener('change', () => {
            state.filters.paid = wrap.querySelector('.nav-sync-paid-filter').value;
            state.page = 1;
            load(wrap);
        });

        wrap.querySelector('.nav-sync-btn-sync').addEventListener('click', () => triggerSync(wrap));

        wrap.querySelector('thead').addEventListener('click', e => {
            const th = e.target.closest('th[data-sort]');
            if (!th) return;
            const key = th.dataset.sort;
            if (state.sort === key) {
                state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sort = key;
                state.dir  = DESC_FIRST.includes(key) ? 'desc' : 'asc';
            }
            state.page = 1;
            markSort(wrap);
            load(wrap);
        });

        markSort(wrap);
        load(wrap);
    }

    // Columns whose first click should show the newest / largest first; text columns start A-Z.
    const DESC_FIRST = ['issue_date', 'completion_date', 'net', 'vat', 'gross'];

    // Reflect the current sort in the header (aria-sort drives the arrow shown by the CSS).
    function markSort(wrap) {
        wrap.querySelectorAll('th[data-sort]').forEach(th => {
            th.setAttribute('aria-sort', th.dataset.sort === state.sort
                ? (state.dir === 'asc' ? 'ascending' : 'descending')
                : 'none');
        });
    }

    function load(wrap) {
        const params = new URLSearchParams({
            action:     'nav_list',
            company_id: state.companyId,
            direction:  state.direction,
            page:       state.page,
            limit:      state.limit,
            sort:       state.sort,
            dir:        state.dir,
            ...state.filters,
        });

        fetch(state.ajaxUrl + '?' + params)
            .then(r => r.json())
            .then(data => render(wrap, data))
            .catch(() => {});
    }

    function render(wrap, data) {
        const tbody = wrap.querySelector('.nav-sync-tbody');
        const empty = wrap.querySelector('.nav-sync-empty');

        tbody.innerHTML = '';

        wrap.querySelector('.nav-sync-tfoot').replaceChildren();

        if (!data.rows || data.rows.length === 0) {
            empty.hidden = false;
            return;
        }
        empty.hidden = true;

        data.rows.forEach(inv => {
            const isHuf    = inv.currency === 'HUF';
            const amountCell = isHuf
                ? `${fmt(inv.gross_amount_huf)} Ft`
                : foreignAmount(inv.currency, inv.gross_amount, inv.gross_amount_huf);

            const statusBadge = inv.paid
                ? `<span class="nav-badge nav-badge-paid">${esc(inv.paid_at)}</span>`
                : (inv.payment_date && inv.payment_date < today()
                    ? `<span class="nav-badge nav-badge-overdue">${esc(state.i18n.overdue)}</span>`
                    : `<span class="nav-badge nav-badge-unpaid">–</span>`);

            const tr = document.createElement('tr');
            tr.className = 'nav-sync-row';
            tr.dataset.invoiceId = inv.id;
            tr.innerHTML = `
                <td>${kindBadge(inv)}</td>
                <td class="nav-inv-number">${esc(inv.invoice_number)}</td>
                <td>${esc(inv.direction === 'INBOUND' ? inv.supplier_name : (inv.customer_name || '–'))}</td>
                <td>${esc(inv.issue_date)}</td>
                <td>${esc(inv.completion_date || '–')}</td>
                <td>${esc(inv.payment_date || '–')}</td>
                <td class="text-right">${isHuf ? `${fmt(inv.net_amount)} Ft` : foreignAmount(inv.currency, inv.net_amount, inv.net_amount_huf)}</td>
                <td class="text-right">${isHuf ? `${fmt(inv.vat_amount)} Ft` : foreignAmount(inv.currency, inv.vat_amount, inv.vat_amount_huf)}</td>
                <td class="text-right">${amountCell}</td>
                <td>${statusBadge}</td>`;

            tbody.appendChild(tr);
            // Extension point for the host app (e.g. a link in the number cell): fired once per drawn invoice row.
            tr.dispatchEvent(new CustomEvent('nav-sync:rows-rendered', { bubbles: true, detail: { invoice: inv, row: tr } }));

            const detail = document.createElement('tr');
            detail.className = 'nav-sync-detail-row';
            detail.style.display = 'none';
            detail.innerHTML = `<td colspan="10"><div class="nav-detail-wrap"></div></td>`;
            tbody.appendChild(detail);

            tr.addEventListener('click', () => toggleDetail(wrap, tr, detail, inv));
        });

        renderTotals(wrap, data);
        renderPagination(wrap, data);
    }

    /** Round badge (colour and white glyph) drawn on the corner of the document icon, per kind (a plain invoice has none); STORNOED is an invoice a storno cancels. */
    const KIND_ICONS = {
        ADVANCE:    { color: '#1f6fd1', glyph: '<path d="M15.6 14.4v5.2l4.2-2.6z" fill="#fff"/>' },
        FINAL:      { color: '#f26a21', glyph: '<path d="M15.4 20v-6.4M15.4 13.8h4.2l-1.3 1.7 1.3 1.7h-4.2" fill="#fff" stroke="#fff" stroke-width="1" stroke-linejoin="round"/>' },
        CORRECTION: { color: '#c58a2b', glyph: '<path d="M14.8 19.3l.4-1.8 3.7-3.7 1.4 1.4-3.7 3.7z" fill="#fff"/>' },
        STORNO:     { color: '#c62828', glyph: '<circle cx="17" cy="17" r="2.9" fill="none" stroke="#fff" stroke-width="1.4"/><path d="M15 19l4-4" stroke="#fff" stroke-width="1.4"/>' },
        STORNOED:   { color: '#d93a3a', glyph: '<path d="M14.7 14.7l4.6 4.6M19.3 14.7l-4.6 4.6" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/>' },
        CORRECTED:  { color: '#8d99ae', glyph: '<path d="M14.8 19.3l.4-1.8 3.7-3.7 1.4 1.4-3.7 3.7z" fill="#fff"/>' },
    };

    /** The icon of one kind: a document with the kind's round badge; the full name is its tooltip and its accessible label. */
    function kindIcon(kind, text) {
        return `<span class="nav-kind-icon" role="img" aria-label="${esc(text)}" title="${esc(text)}"><svg viewBox="0 0 24 24" aria-hidden="true">`
            + '<path d="M5 2h9l5 5v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" fill="#fff" stroke="#5b6475" stroke-width="1.4" stroke-linejoin="round"/>'
            + '<path d="M14 2v5h5" fill="none" stroke="#5b6475" stroke-width="1.4" stroke-linejoin="round"/><path d="M7.5 11h6M7.5 14h3" stroke="#aab1bf" stroke-width="1.4" stroke-linecap="round"/>'
            + (KIND_ICONS[kind] ? `<circle cx="17" cy="17" r="6.2" fill="${KIND_ICONS[kind].color}" stroke="#fff" stroke-width="1.2"/>${KIND_ICONS[kind].glyph}` : '')
            + '</svg></span>';
    }

    /**
     * Icons of the kind column: the kind of the invoice (a plain invoice is the document icon alone; an unknown kind shows nothing),
     * and "corrected" / "stornoed" when a correcting or storno invoice names it as its original; those stand in for the plain
     * icon instead of doubling it.
     */
    function kindBadge(inv) {
        const icons = [];
        const marked = inv.stornoed_by || inv.corrected_by;
        if (inv.invoice_kind && (inv.invoice_kind !== 'INVOICE' || !marked)) {
            icons.push(kindIcon(inv.invoice_kind, state.i18n.kinds[inv.invoice_kind]));
        }
        if (inv.corrected_by) {
            icons.push(kindIcon('CORRECTED', state.i18n.corrected));
        }
        if (inv.stornoed_by) {
            icons.push(kindIcon('STORNOED', state.i18n.stornoed));
        }
        return icons.join('');
    }

    /** Right side of the opened invoice's header: its kind, the invoice it refers to and the bank account (each only when known). */
    function detailMeta(inv) {
        const parts = [];
        if (inv.invoice_kind) {
            parts.push(`${esc(state.i18n.type)}: <strong>${esc(state.i18n.kinds[inv.invoice_kind])}</strong>`);
        }
        if (inv.referenced_invoice_number) {
            // a final invoice refers to an advance invoice, a correction or storno to the original one
            const label = inv.invoice_kind === 'FINAL' ? state.i18n.kinds.ADVANCE : state.i18n.originalInvoice;
            parts.push(`${esc(label)}: <strong>${esc(inv.referenced_invoice_number)}</strong>`);
        }
        if (inv.corrected_by) {
            parts.push(`${esc(state.i18n.kinds.CORRECTION)}: <strong>${esc(inv.corrected_by)}</strong>`);
        }
        if (inv.stornoed_by) {
            parts.push(`${esc(state.i18n.kinds.STORNO)}: <strong>${esc(inv.stornoed_by)}</strong>`);
        }
        // the buyer address (typed in for a private person, whom NAV sends no address of): "2000 Szentendre, Pomázi út 60., Magyarország"
        const address = [[inv.customer_postcode, inv.customer_city].filter(Boolean).join(' '), inv.customer_street, inv.customer_country].filter(Boolean).join(', ');
        if (address) {
            parts.push(`${esc(state.i18n.customerAddress)}: <strong>${esc(address)}</strong>`);
        }
        if (inv.supplier_bank_account) {
            parts.push(`${esc(state.i18n.bankAccount)}: <strong>${esc(inv.supplier_bank_account)}</strong>`);
        }
        return parts.length ? `<span class="nav-detail-meta">${parts.join('')}</span>` : '';
    }

    /**
     * Footer under the list: the invoice count, then one row per currency with the sums of the net, VAT and gross amounts and
     * the outstanding (unpaid) amount. The server computes them over the whole current selection (filters, not just this page).
     * Built with DOM nodes and textContent, so nothing from the data is ever parsed as HTML.
     */
    function renderTotals(wrap, data) {
        const cell = (content, colSpan = 1, right = false) => {
            const td = document.createElement('td');
            td.colSpan = colSpan;
            if (right) td.className = 'text-right';
            td.append(...[].concat(content));
            return td;
        };
        const strong = text => Object.assign(document.createElement('strong'), { textContent: text });

        const row = (...cells) => {
            const tr = document.createElement('tr');
            tr.className = 'nav-sync-total-row';
            tr.append(...cells);
            return tr;
        };
        const totals = data.totals || [];
        if (totals.length === 0) return;

        // first row: the invoice count, and the heading of the outstanding column (it sits under the status column)
        wrap.querySelector('.nav-sync-tfoot').replaceChildren(
            row(cell([`${state.i18n.totalCount}: `, strong(String(data.total))], 9), cell(state.i18n.outstanding, 1, true)),
            ...totals.map(t => row(
                cell('', 3),
                cell(state.i18n.totalInvoices.replace('{count}', t.invoices), 3, true),
                cell(moneyText(t.net, t.currency), 1, true),
                cell(moneyText(t.vat, t.currency), 1, true),
                cell(moneyText(t.gross, t.currency), 1, true),
                cell(moneyText(t.outstanding, t.currency), 1, true)
            ))
        );
    }

    /** A sum in its own currency as plain text: whole forints for HUF, two decimals otherwise. */
    function moneyText(value, currency) {
        if (currency === 'HUF') return `${fmt(value)} Ft`;
        return `${number(value, 2, 2)} ${currency}`;
    }

    function toggleDetail(wrap, tr, detailRow, inv) {
        const open = detailRow.style.display !== 'none';
        if (open) {
            detailRow.style.display = 'none';
            return;
        }

        const inner = detailRow.querySelector('.nav-detail-wrap');
        inner.innerHTML = `<em>${esc(state.i18n.loading)}</em>`;
        detailRow.style.display = '';

        const params = new URLSearchParams({
            action:     'nav_lines',
            invoice_id: inv.id,
            company_id: state.companyId,
        });

        fetch(state.ajaxUrl + '?' + params)
            .then(r => r.json())
            .then(lines => {
                if (!lines.length && !Number(inv.detail_fetched)) { inner.innerHTML = '–'; return; }
                const isHuf = inv.currency === 'HUF';
                const rows = lines.map(l => `
                    <tr>
                        <td>${esc(l.line_description || '–')}</td>
                        <td>${quantity(l.quantity)}${unitCell(l)}</td>
                        <td class="text-right">${isHuf ? `${fmt(l.unit_price_huf)} Ft` : foreignAmount(inv.currency, l.unit_price, unitPriceHuf(l), 4)}</td>
                        <td class="text-center">${esc(l.vat_rate || '–')}%</td>
                        <td class="text-right">${isHuf ? `${fmt(l.net_amount_huf)} Ft` : foreignAmount(inv.currency, l.net_amount, l.net_amount_huf)}</td>
                        <td class="text-right">${isHuf ? `${fmt(l.gross_amount_huf)} Ft` : foreignAmount(inv.currency, l.gross_amount, l.gross_amount_huf)}</td>
                    </tr>`).join('');
                inner.innerHTML = `
                    <div class="nav-detail-header">
                        ${detailMeta(inv)}
                        <span class="nav-detail-pay">
                            ${inv.paid
                                ? `<button class="nav-btn-mark-unpaid" data-id="${esc(inv.id)}">${esc(state.i18n.markUnpaid)}</button>`
                                : `<label>${esc(state.i18n.paymentDate)}: <input type="date" class="nav-paid-date" value="${today()}"></label>
                                   <button class="nav-btn-mark-paid" data-id="${esc(inv.id)}">${esc(state.i18n.markPaid)}</button>`}
                        </span>
                    </div>
                    ${lines.length ? `<table class="nav-lines-table">
                        <thead><tr>
                            <th>${esc(state.i18n.lineDesc)}</th><th>${esc(state.i18n.lineQty)}</th><th class="text-right">${esc(state.i18n.lineUnitPrice)}</th>
                            <th class="text-center">${esc(state.i18n.lineVat)}</th><th class="text-right">${esc(state.i18n.lineNet)}</th><th class="text-right">${esc(state.i18n.lineGross)}</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>` : '–'}`;

                inner.querySelector('.nav-btn-mark-paid')?.addEventListener('click', () => {
                    const paidAt = inner.querySelector('.nav-paid-date').value;
                    markPaid(wrap, inv.id, true, paidAt);
                });
                inner.querySelector('.nav-btn-mark-unpaid')?.addEventListener('click', () => {
                    markPaid(wrap, inv.id, false, null);
                });

                // Extension point for the host app (e.g. an extra column): fired once per rendered line table.
                inner.dispatchEvent(new CustomEvent('nav-sync:lines-rendered', { bubbles: true, detail: { invoice: inv, lines } }));
            });
    }

    function markPaid(wrap, invoiceId, paid, paidAt) {
        const body = new URLSearchParams({
            _csrf:      csrfToken(),
            action:     'nav_mark_paid',
            invoice_id: invoiceId,
            company_id: state.companyId,
            paid:       paid ? 1 : 0,
            paid_at:    paidAt || '',
        });

        fetch(state.ajaxUrl, { method: 'POST', body })
            .then(r => r.json())
            .then(d => { if (d.success) load(wrap); });
    }

    function triggerSync(wrap) {
        const btn = wrap.querySelector('.nav-sync-btn-sync');
        btn.disabled = true;

        const body = new URLSearchParams({
            _csrf:      csrfToken(),
            action:     'nav_sync',
            company_id: state.companyId,
        });

        const status = wrap.querySelector('.nav-sync-last-sync');
        status.textContent = state.i18n.syncRunning;

        fetch(state.ajaxUrl, { method: 'POST', body })
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                // textContent only: NAV's error text is data, never markup.
                if (d.error) {
                    status.textContent = `${state.i18n.syncFailed} ${d.error}`;
                } else if (d.result && d.result.errors.length) {
                    status.textContent = `${state.i18n.syncFailed} ${d.result.errors[0]}`;
                } else if (!d.complete) {
                    status.textContent = state.i18n.syncIncomplete;
                } else {
                    status.textContent = state.i18n.syncDone.replace('%s', d.result ? d.result.fetched : 0);
                }
                load(wrap);
            })
            .catch(() => { btn.disabled = false; status.textContent = state.i18n.syncFailed; });
    }

    function renderPagination(wrap, data) {
        const pages = Math.ceil(data.total / state.limit);
        const pg    = wrap.querySelector('.nav-sync-pagination');
        if (pages <= 1) { pg.innerHTML = ''; return; }

        let html = '';
        for (let i = 1; i <= pages; i++) {
            html += `<button class="nav-pg-btn${i === state.page ? ' active' : ''}" data-page="${i}">${i}</button>`;
        }
        pg.innerHTML = html;
        pg.querySelectorAll('.nav-pg-btn').forEach(b => {
            b.addEventListener('click', () => {
                state.page = parseInt(b.dataset.page, 10);
                load(wrap);
            });
        });
    }

    function fmt(val) {
        if (val === null || val === undefined || val === '') return '–';
        return number(val, 0, 0);
    }

    /** A line quantity without trailing zeros, in the Hungarian format of the amounts ("3.0000000000" => "3", "2.5000000000" => "2,5"); '–' when unknown. */
    function quantity(val) {
        if (val === null || val === undefined || val === '' || !isFinite(parseFloat(val))) return '–';
        return esc(number(val, 0, 10));
    }

    /** The unit of a line: the host's label of the supplier's own text or of the NAV code (the original is the tooltip), else the value as written. */
    function unitCell(l) {
        const raw = String(l.unit_of_measure_own || l.unit_of_measure || '').trim();
        if (raw === '') return '';
        const alias = raw.toLowerCase().replace(/\.+$/, '');
        const label = Object.prototype.hasOwnProperty.call(state.unitLabels, alias) ? state.unitLabels[alias] : undefined;
        return label === undefined || label === raw ? ` ${esc(raw)}` : ` <span title="${esc(raw)}">${esc(label)}</span>`;
    }

    /** Hungarian number format with the thousands separator from 1 000 up (hu-HU alone groups only from 10 000). */
    function number(val, minDecimals, maxDecimals) {
        return parseFloat(val).toLocaleString('hu-HU', { useGrouping: 'always', minimumFractionDigits: minDecimals, maximumFractionDigits: maxDecimals });
    }

    /** Amount of a foreign-currency invoice: the original amount with its currency, the forint value below it ('–' when neither is known). */
    function foreignAmount(currency, amount, amountHuf, maxDecimals = 2) {
        const has = v => v !== null && v !== undefined && v !== '';
        const parts = [];
        if (has(amount)) {
            parts.push(`${number(amount, 2, maxDecimals)} ${esc(currency)}`);
        }
        if (has(amountHuf)) parts.push(`<small>${fmt(amountHuf)} Ft</small>`);
        return parts.length ? parts.join('<br>') : '–';
    }

    /** HUF unit price of a foreign-currency line: NAV's own when it sent one, else the original unit price at the line's own HUF/original rate. */
    function unitPriceHuf(line) {
        if (line.unit_price_huf !== null && line.unit_price_huf !== '') return line.unit_price_huf;
        const net = parseFloat(line.net_amount), netHuf = parseFloat(line.net_amount_huf), unit = parseFloat(line.unit_price);
        return net && !isNaN(netHuf) && !isNaN(unit) ? String(unit * netHuf / net) : null;
    }

    function esc(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function today() {
        return new Date().toISOString().slice(0, 10);
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    function debounce(fn, ms) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    document.querySelectorAll('.nav-sync-invoices').forEach(init);
})();

(function () {
    'use strict';

    const vatWrap = document.querySelector('.nav-sync-vat-report');
    if (!vatWrap) return;

    const companyId   = parseInt(vatWrap.dataset.companyId, 10);
    const ajaxUrl     = vatWrap.dataset.ajaxUrl;
    const i18nTotal   = vatWrap.dataset.i18nTotal;
    const i18nPayable = vatWrap.dataset.i18nPayable;
    const i18nCov     = {
        ok:      vatWrap.dataset.i18nCovOk,
        partial: vatWrap.dataset.i18nCovPartial,
        none:    vatWrap.dataset.i18nCovNone,
        nodate:  vatWrap.dataset.i18nCovNodate,
        novat:   vatWrap.dataset.i18nCovNovat,
    };

    // Replaces {name} placeholders (named, because word order differs between languages).
    function fill(template, values) {
        return String(template).replace(/\{(\w+)\}/g, (m, key) => (key in values ? values[key] : m));
    }

    // How complete the month is: the amounts come from each invoice's VAT summary, downloaded newest-first.
    function renderCoverage(cov) {
        const box = vatWrap.querySelector('.nav-vat-coverage');
        box.replaceChildren();
        if (!cov) return;
        const add = (text, level) => {
            const line = document.createElement('div');
            line.className   = 'nav-vat-coverage-' + level;
            line.textContent = text;   // text only: never markup
            box.appendChild(line);
        };
        if (cov.total === 0) {
            add(fill(i18nCov.none, {}), 'none');
        } else if (cov.fetched >= cov.total) {
            add(fill(i18nCov.ok, { total: cov.total }), 'ok');
        } else {
            add(fill(i18nCov.partial, {
                total: cov.total, fetched: cov.fetched,
                in_fetched: cov.inbound.fetched, in_total: cov.inbound.total,
                out_fetched: cov.outbound.fetched, out_total: cov.outbound.total,
            }), 'warn');
        }
        if (cov.no_vat_data > 0) {
            add(fill(i18nCov.novat, { count: cov.no_vat_data }), 'warn');
        }
        if (cov.no_completion_date > 0) {
            add(fill(i18nCov.nodate, { count: cov.no_completion_date }), 'warn');
        }
    }

    function esc(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmt(val) {
        if (!val && val !== 0) return '–';
        return parseFloat(val).toLocaleString('hu-HU', { useGrouping: 'always', minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' Ft';
    }

    function load() {
        const month  = vatWrap.querySelector('.nav-vat-month').value;
        const params = new URLSearchParams({ action: 'nav_vat', company_id: companyId, month });

        fetch(ajaxUrl + '?' + params)
            .then(r => r.json())
            .then(render)
            .catch(() => {});
    }

    function render(data) {
        renderCoverage(data.coverage);
        const byRate = {};
        (data.rows || []).forEach(r => {
            if (!byRate[r.vat_rate]) {
                byRate[r.vat_rate] = { outNet: 0, outVat: 0, inNet: 0, inVat: 0 };
            }
            if (r.direction === 'OUTBOUND') {
                byRate[r.vat_rate].outNet += parseFloat(r.net_huf);
                byRate[r.vat_rate].outVat += parseFloat(r.vat_huf);
            } else {
                byRate[r.vat_rate].inNet  += parseFloat(r.net_huf);
                byRate[r.vat_rate].inVat  += parseFloat(r.vat_huf);
            }
        });

        const rates = Object.keys(byRate).sort((a, b) => parseFloat(b) - parseFloat(a));

        vatWrap.querySelector('.nav-vat-tbody').innerHTML = rates.map(rate => `
            <tr>
                <td>${parseFloat(rate) > 0 ? parseFloat(rate) + '%' : esc(rate)}</td>
                <td class="text-right">${fmt(byRate[rate].outNet)}</td>
                <td class="text-right">${fmt(byRate[rate].outVat)}</td>
                <td class="text-right">${fmt(byRate[rate].inNet)}</td>
                <td class="text-right">${fmt(byRate[rate].inVat)}</td>
            </tr>`).join('');

        vatWrap.querySelector('.nav-vat-tfoot').innerHTML = `
            <tr class="nav-vat-total">
                <th>${esc(i18nTotal)}</th>
                <th class="text-right"></th>
                <th class="text-right">${fmt(data.outbound_vat_total)}</th>
                <th class="text-right"></th>
                <th class="text-right">${fmt(data.inbound_vat_total)}</th>
            </tr>`;

        const payable = parseFloat(data.payable_vat || 0);
        vatWrap.querySelector('.nav-vat-payable').innerHTML =
            `<strong>${esc(i18nPayable)}: <span class="${payable >= 0 ? 'nav-vat-positive' : 'nav-vat-negative'}">${fmt(payable)}</span></strong>`;
    }

    vatWrap.querySelector('.nav-vat-month').addEventListener('change', load);
    load();
})();
