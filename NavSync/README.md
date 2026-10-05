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

## Security notes

- `nav_sign_key` is AES-256-GCM encrypted at rest; the encryption key lives only in `.env`
- `CompanyRepository::encrypt()` / `decrypt()` are public so the host app can store its own per-company secrets (e.g. the Számlázz.hu Számla Agent key, kept in the host's `szamlazz_agent_keys` table) encrypted under the same key; the module itself never touches them
- Authentication and authorisation must be enforced by the host app before delegating to `AdminActions`
- CSRF protection is the host app's job too: `AdminActions` checks no token. The state-changing POST actions (`nav_mark_paid`, `nav_sync`) must only be reachable through a route that already verifies a CSRF token, and `js/nav-sync.js` sends it as the `_csrf` field read from `<meta name="csrf-token">`
- The module only calls NAV query endpoints — no invoice submission
