# NavSync

NAV Online Számla API v3.0 integration module for PHP applications. Syncs incoming and outgoing invoices into a local MariaDB store, tracks payment status, and provides a monthly VAT summary report.

## Requirements

- PHP 8.3+ with `openssl`, `curl`, `bcmath` extensions
- MariaDB 10.5+
- `NAV_SIGN_KEY_SECRET` in `.env` (64-char hex, 32 random bytes)
- The `ErrorHandling` module (`ErrorHandling\ErrorHandler`), initialised by the host: every failed NAV request, sync step and admin action is logged there with the company, step and invoice involved

## Installation

### 1. Create the database tables

```bash
mariadb -u root -p your_db < lib/NavSync/schema.sql
```

### 2. Set the encryption key

Generate a 32-byte key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Add to `.env`:

```
NAV_SIGN_KEY_SECRET=<64-char hex from above>
```

### 3. Bootstrap the module

```php
$navSync = new NavSync\NavSync(
    $pdo,
    $_ENV['NAV_SIGN_KEY_SECRET'],
    [
        'softwareId'          => 'YOUR_SOFTWARE_ID',
        'softwareName'        => 'YOUR_APP',
        'softwareMainVersion' => '1.0.0',
        'softwareDevName'     => 'PatrikMol Solutions Kft.',
        'softwareDevContact'  => 'patrikmol@patrikmol.com',
    ],
    '2020-01-01'   // optional: first day imported on a company's very first sync
);
```

### 4. Merge translations

```php
$messages = array_merge($messages, require __DIR__ . '/lib/NavSync/locale/hu_HU/messages.php');
```

### 5. Route AJAX requests

```php
if ($_GET['module'] === 'nav') {
    $actions = $navSync->getAdminActions();
    match ($_GET['action'] ?? '') {
        'nav_list'      => $actions->listInvoices(),
        'nav_lines'     => $actions->getLines(),
        'nav_mark_paid' => $actions->markPaid(),
        'nav_sync'      => $actions->triggerSync(),
        'nav_vat'       => $actions->vatReport(),
        default         => http_response_code(404),
    };
}
```

### 6. Register the cron job (daily sync)

```php
$cronAdmin->register('nav_sync_all', '0 6 * * *', function () use ($navSync) {
    $navSync->syncAll();
});
```

### 7. Include views

```php
// Invoice list
$companyId = (int)$_SESSION['active_company_id'];
$ajaxUrl   = '/admin?module=nav';
$unitLabels = ['piece' => 'db', 'db' => 'db'];   // optional: normalised unit alias (trimmed, lower case, no trailing dot) => label shown in the line tables
require __DIR__ . '/lib/NavSync/views/invoices.php';

// VAT report
require __DIR__ . '/lib/NavSync/views/vat-report.php';
```

### 8. Include assets

```html
<link rel="stylesheet" href="/lib/NavSync/css/nav-sync.css">
<script src="/lib/NavSync/js/nav-sync.js" defer></script>
```

The invoice list fires a bubbling `nav-sync:lines-rendered` event on the invoice's detail element every time it has rendered the line table of an opened invoice (`event.detail.invoice`: the invoice row, `event.detail.lines`: the lines as returned by `nav_lines`). A host app can listen for it to decorate the table (e.g. an extra column) without depending on the module's markup.

It also fires a bubbling `nav-sync:rows-rendered` event on every invoice row it draws in the list (`event.detail.invoice`: the invoice row, `event.detail.row`: the `<tr>` element), so a host app can add something to a row (e.g. a link in the `.nav-inv-number` cell). A click handler added there should stop propagation, since a click on the row opens the invoice detail.

## VAT report

`VatReportService::monthly()` sums the per-rate VAT summary of each invoice (`nav_invoice_vat`, from the NAV `invoiceSummary`) by completion date. It deliberately does not sum invoice lines: NAV makes per-line VAT optional, and simplified invoices carry only gross line amounts (their net and VAT are derived from NAV's VAT content ratio).

Simplified invoices (`invoiceCategory` SIMPLIFIED) have no net or VAT amount in their digest either: the sync takes their net, VAT and gross from the invoice data (`summarySimplified`, split per rate exactly as for the VAT summary, so the invoice amounts always equal the sum of its `nav_invoice_vat` rows), and `upsertFromDigest()` does not overwrite the amounts of a simplified invoice. The result includes a `coverage` block (how many of the month's invoices have their detail downloaded, how many came back without a summary, how many have no completion date), which the UI shows above the table.

## Synchronisation

`NavSync::syncAll()` (cron / CLI) and the manual "Sync now" button run the same service:

- **Sliced history.** NAV rejects a `queryInvoiceDigest` interval longer than 35 days (`BAD_QUERY_PARAM_RANGE_EXCEEDED`), so the history is fetched in slices of 30 days, `OUTBOUND` then `INBOUND` per slice, from the configured start date up to now.
- **Progress is saved per slice.** `nav_companies.last_sync_at` moves to a slice's end only after both directions succeeded. A NAV error, a crash or an exhausted time budget therefore never loses progress or leaves a gap: the next run resumes there (with a one-hour overlap on incremental runs). The cursor never moves backwards.
- **Invoice lines are fetched separately**, newest first, for every invoice whose detail is still missing. Failed invoices are retried on the next run; the detail phase gives up after 5 consecutive failures (e.g. wrong credentials) instead of hammering NAV.
- **Invoice type (invoice, advance, final, correction, storno).** The digest's `invoiceOperation` is stored in `nav_invoices.invoice_operation` (CREATE, MODIFY = correction, STORNO). The invoice data gives `advance_type` (NONE, ADVANCE, FINAL) and `referenced_invoice_number` (the original invoice of a correction or storno, else the advance invoice). NAV marks the lines an advance affects (`advanceIndicator`) the same way on an advance invoice and on the final invoice settling it, and its advance reference is optional, so ADVANCE and FINAL come from a rule, not a NAV field: every line marked and no reference = ADVANCE; a line with an advance reference, or only some lines marked = FINAL; no marked line = NONE. `InvoiceType::of()` turns the two columns into the kind (the list rows carry it as `invoice_kind`); NULL means not known yet. The list rows also carry `stornoed_by` and `corrected_by`: the number of the storno or correcting invoice that names the row as its original (same company and direction), so the original is marked too. Invoices fetched before the type existed are filled in by the next sync: the digest scan sets the operation (reset `nav_companies.last_sync_at` to NULL to scan the whole history again; it is idempotent), the detail phase then asks NAV for the data of every such invoice and stores only the type, leaving the saved lines alone.
- **NAV allows ~30 requests/minute** (the client sleeps 2 s per call), so the very first full history takes hours. Run it from the CLI without a budget (`syncAll()`); the web button passes a 20-second budget and reports `complete: false` when it stopped early.
- **One sync per company at a time**, enforced with a database lock (`GET_LOCK`); a second start returns immediately with an error.

## Cash registers (NAV OPF)

Optional part of the module: it downloads the receipt log files (OPF) of the company's online cash registers from NAV (`queryCashRegisterStatus`, `queryCashRegisterFile`), stores each raw file, parses the receipts and simplified invoices into the `nav_cash_*` tables, and adds the cash register VAT to the monthly VAT report. It is off for every company until switched on.

### NAV-side prerequisites

- The same technical user as for the invoices is used, but it needs the log-query permission, which the primary user grants in the NAV web interface.
- NAV's test and production environments have separate users. The environment is the last constructor argument of `NavSync`: `$cashRegisterEnvironment`, `'production'` (default) or `'test'`.

```php
$navSync = new NavSync\NavSync($pdo, $_ENV['NAV_SIGN_KEY_SECRET'], $software, '2020-01-01', 'production');
```

### Requirements

- PHP extensions `zip`, `dom`, `mbstring`, `iconv`, `bcmath`, `curl` (the `openssl` requirement above still applies)
- A writable `storage/` directory next to the `lib/` directory that contains the module (two levels above `NavSync.php`). The raw files go to `storage/navsync/cash-register/<company id>/<AP number>/<AP number>_<file number>.p7b`; the folders are created on demand.
- That directory must be outside the document root, must not be committed to git, and must be included in the backups. NAV keeps the files only about 14 days, so after that the stored raw file is the only copy.

### Database

- New installs: `schema.sql` already contains everything.
- Existing installs: run `migrations/2026_10_13_190000_nav_cash_register.sql` (idempotent: five `nav_cash_*` tables, the `nav_companies.cash_register_sync` column, the `CASH_REGISTER` value of `nav_sync_log.direction`). PM-ERP applies it through its own `database/migrations/`; JupitERP through its copy in `addons/nav_szinkron/migrations/install.sql` (and its migration list). In JupitERP's uninstall the five `nav_cash_*` tables must be dropped before `nav_companies`, because they reference it.
- **Deploy order:** the migration must ship in the same patch as the module update, or before it. `VatReportService` now reads the `nav_cash_*` tables, so on a database without them the VAT report fails.

### Enabling a company

`nav_companies.cash_register_sync` (0/1, default 0) is the opt-in. Set it from the host's company form with `$navSync->companies()->setCashRegisterSync($companyId, true)`. The daily job and the web actions skip or refuse (`not_enabled`) companies where it is 0.

### Daily cron job

```php
$cronAdmin->register('nav_cash_register_sync', '30 6 * * *', function () use ($navSync) {
    $navSync->syncCashRegisters();   // optional argument: time budget in seconds per company
});
```

**NAV keeps the files only about 14 days. The job must run at least daily; a missed window loses files permanently** (the run logs the lost file numbers as step `cash_register_gap`).

The cron job must run as the web server user, or in its group with `umask 002`: the web "sync now" and "reparse" actions write into the same `storage/navsync/cash-register/` folders, so both sides must be able to write there.

### Admin actions

Route them like the invoice actions (all through the host's auth and CSRF-protected routing). The `action` names below are the ones `js/nav-cash-register.js` sends; the host maps them to the `AdminActions` methods.

| Action             | Method | HTTP | Parameters                                                        |
|--------------------|--------|------|-------------------------------------------------------------------|
| `nav_cr_list`      | `listCashReceipts()`        | GET  | `company_id`, optional `ap`, `dateFrom`, `dateTo` (Y-m-d), `limit` (1-200, default 50), `offset` |
| `nav_cr_lines`     | `getCashReceiptLines()`     | GET  | `company_id`, `receipt_id`                                        |
| `nav_cr_status`    | `cashRegisterStatus()`      | GET  | `company_id` (registers with file counters, files needing attention, last sync) |
| `nav_cr_sync`      | `triggerCashRegisterSync()` | POST | `company_id` (time-boxed to 20 s, like `nav_sync`)                |
| `nav_cr_reparse`   | `reparseCashRegisterFile()` | POST | `company_id`, `file_id` (reprocesses the stored file, no NAV contact) |

```php
'nav_cr_list'    => $actions->listCashReceipts(),
'nav_cr_lines'   => $actions->getCashReceiptLines(),
'nav_cr_status'  => $actions->cashRegisterStatus(),
'nav_cr_sync'    => $actions->triggerCashRegisterSync(),
'nav_cr_reparse' => $actions->reparseCashRegisterFile(),
```

The two POST actions must only be reachable through a route that verifies the CSRF token (`nav-sync.js` sends it as `_csrf`).

### View and assets

```php
// Host provides: int $companyId, callable $t, string $ajaxUrl (as for the other views)
require __DIR__ . '/lib/NavSync/views/cash-registers.php';
```

```html
<script src="/lib/NavSync/js/nav-sync.js" defer></script>
<script src="/lib/NavSync/js/nav-cash-register.js" defer></script>
```

Load `nav-sync.js` first: it publishes the shared helpers on `window.NavSyncUi`, which `nav-cash-register.js` needs. Add the new `TEXT_NAV_CR_*` and cash VAT keys by merging the module's locale files again (see step 4). A host that serves its own copy of the JavaScript must re-copy **both** files together with the PHP update: an old `nav-sync.js` ignores `cash_rows` and shows the VAT report without the cash register VAT.

### VAT report changes

`VatReportService::monthly()` additionally returns:

- `cash_rows`: list of `vat_rate`, `net_huf`, `vat_huf` per rate, kept apart from `rows` (the invoice rows)
- `cash_vat_total`
- `payable_vat = outbound + cash_vat_total - inbound`

Only receipts that count are included, selected by closing time (Budapest wall-clock time) within the month: practice mode and interrupted receipts are excluded. New `coverage` keys:

| Key                        | Meaning                                                                                  |
|----------------------------|------------------------------------------------------------------------------------------|
| `cash.receipts`            | counted receipts of the month                                                            |
| `cash.unmapped_lines`      | lines of those receipts without a VAT rate (not in the amounts)                          |
| `cash_files_not_processed` | files of the company that are not parsed (pending, error or skipped), whatever month     |
| `cash_unsynced`            | registers where NAV holds newer files than the last one downloaded                       |

### Known limitations

- **The VAT rate mapping is unverified.** Rates are read from the log's `INF.VAT` table, and its format has not yet been checked against real NAV data. Until that is confirmed, receipt lines get no VAT rate (shown as unmapped lines in the coverage) and no cash register VAT appears in the report.
- Files that NAV deleted before they were downloaded are not recorded in the database, only in the sync log and the application log.
- The signature of the downloaded `.p7b` files (P7B) is not verified.

### Errors

Every failure is logged through the `ErrorHandling` module with `module` = `NavSync`, the `step` (all `cash_register_*`, e.g. `cash_register_sync`, `cash_register_gap`, `cash_register_store`, `cash_register_parse`, `cash_register_vat_table`, `cash_register_reparse`) and the company, AP number and file number involved.

## Security notes

- `nav_sign_key` is AES-256-GCM encrypted at rest; the encryption key lives only in `.env`
- `CompanyRepository::encrypt()` / `decrypt()` are public so the host app can store its own per-company secrets (e.g. the Számlázz.hu Számla Agent key, kept in the host's `szamlazz_agent_keys` table) encrypted under the same key; the module itself never touches them
- Authentication and authorisation must be enforced by the host app before delegating to `AdminActions`
- CSRF protection is the host app's job too: `AdminActions` checks no token. The state-changing POST actions (`nav_mark_paid`, `nav_sync`) must only be reachable through a route that already verifies a CSRF token, and `js/nav-sync.js` sends it as the `_csrf` field read from `<meta name="csrf-token">`
- The module only calls NAV query endpoints — no invoice submission
