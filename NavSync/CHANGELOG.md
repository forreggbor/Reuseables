# NavSync Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — 2026-10-05

| Category | Description                                                                       |
|----------|-----------------------------------------------------------------------------------|
| Added    | Every failed NAV request, sync step and admin action is written to the application log |
| Changed  | An unexpected error in one company's sync no longer stops the sync of the others  |
| Changed  | The admin actions answer a failed database access with a plain error, not a crash |
| Fixed    | Errors that are not exceptions (for example a wrong value type) are caught and reported like any other failure |

### Added

- Failed NAV requests, failed sync steps (digests, invoice data, invoice type), refused syncs and failed admin actions are logged with the company, the step and the invoice involved, so the cause can be traced in the application log (refs #149)
- The module now requires the `ErrorHandling` module, set up by the host application

### Changed

- If something unexpected goes wrong during one company's sync, the sync reports it ("see the application log") and carries on with the next company instead of stopping
- The invoice list, line table, payment marking, manual sync and VAT report answer a failed database access with a short error message; the details are only in the log

### Fixed

- Errors that are not exceptions, such as a wrong value type, used to slip past the error handling of the sync and the connection test; they are now caught, logged and reported like any other failure

## 0.6.0 — 2026-10-05

| Category | Description                                                                  |
|----------|------------------------------------------------------------------------------|
| Removed  | The Számlázz.hu Számla Agent key is no longer stored by the module           |
| Changed  | The host application can encrypt and decrypt its own secrets with the module's key |

### Removed

- The optional per-company Számlázz.hu Számla Agent key (added in 0.4.0) is no longer part of the module; it has nothing to do with the NAV synchronisation, so the host application keeps it in its own table (refs #139)

### Changed

- The host application can encrypt and decrypt its own per-company secrets under the same key as the NAV signing key; the module itself uses this only for the signing key

## 0.5.0 — 2026-10-04

| Category | Description                                                              |
|----------|--------------------------------------------------------------------------|
| Added    | Buyer address of invoices issued to private persons can be stored        |
| Changed  | A buyer name typed in by hand is kept when the invoices are synced again |

### Added

- The buyer address (country, postcode, city, street) of an invoice can be stored; NAV sends neither name nor address for a private-person buyer, so they are entered from the issuing system's list (refs #116)

### Changed

- A buyer name typed in by hand is no longer overwritten by the next NAV sync (refs #101)

## 0.4.0 — 2026-10-02

| Category | Description                                                                  |
|----------|------------------------------------------------------------------------------|
| Added    | Invoice kind (invoice, advance, final, correction, storno) with icons         |
| Added    | Original invoice number shown for corrections, storno and final invoices      |
| Added    | Totals footer under the invoice list, per currency, with the outstanding sum  |
| Added    | Optional per-company Számlázz.hu Számla Agent key, stored encrypted           |
| Added    | Extension points for the host application on the invoice list                 |
| Added    | Configurable unit labels in the line tables                                   |
| Changed  | Foreign-currency amounts are shown in the original currency with the Ft value |
| Changed  | The bank account moved from the list column to the opened invoice's header    |
| Fixed    | Simplified invoices now get their net, VAT and gross amounts                  |

### Added

- The kind of every invoice is shown as an icon in the list and in the opened invoice; invoices that were corrected or cancelled by a storno are marked as well (refs #81)
- A correction, storno or final invoice shows the number of the invoice it refers to
- The list shows the number of invoices and, per currency, the net, VAT and gross sums and the unpaid amount for the whole current selection, not only the visible page
- A company can have a Számlázz.hu Számla Agent key; it is stored encrypted like the NAV signing key and is left empty when not used
- The invoice list announces drawn rows and opened line tables, so the host application can add its own links and actions
- Unit names in the line tables can be replaced with display labels set by the host application

### Changed

- Amounts in a foreign currency show the original amount with the Ft equivalent below it; Ft amounts show the "Ft" unit
- The supplier's bank account is shown in the header of the opened invoice instead of a separate column

### Fixed

- Simplified invoices carry no amounts in the NAV list; their net, VAT and gross amounts are now read from the invoice data

## 0.3.1 — 2026-09-25

| Category | Description                                                |
|----------|------------------------------------------------------------|
| Fixed    | Invoices issued before 2021 are read correctly             |

### Fixed

- Invoices that NAV stores in the older (2.0) data format, i.e. those issued before 2021, are now read; the affected invoices are downloaded again

## 0.3.0 — 2026-09-23

| Category | Description                                                              |
|----------|--------------------------------------------------------------------------|
| Added    | Sortable columns in the invoice lists                                    |
| Added    | Connection test and last-sync information for a company                  |
| Changed  | The VAT report is based on the VAT summary of each invoice               |
| Fixed    | The synchronisation with the live NAV system works again                 |
| Fixed    | Invoice amounts, completion dates and currencies are read correctly      |
| Fixed    | NAV error messages are reported with their code and text                 |

### Added

- Invoice list columns can be sorted (fixed set of columns, Hungarian alphabetical order)
- A company's NAV credentials can be tested with one read-only request, and the result of its newest sync (time, count, error) can be shown

### Changed

- The monthly VAT report sums the per-rate VAT summary of each invoice instead of the invoice lines, because line-level VAT is optional in NAV data; it also shows how many invoices of the month are fully downloaded, lack a summary or have no completion date

### Fixed

- NAV requests are signed the way the live NAV system expects, so authentication works
- NAV responses are read regardless of the prefix NAV uses for its data; the gross amount is net plus VAT, the completion date comes from the delivery date, times are normalised to UTC, and Ft invoices without a unit price in Ft are accepted
- Queries for issued invoices no longer send a supplier tax number
- NAV errors are shown with their code and message instead of a generic failure

## 0.2.0 — 2026-09-23

| Category | Description                                                               |
|----------|---------------------------------------------------------------------------|
| Added    | History is downloaded in 30-day slices with saved progress                |
| Added    | Time budget for the manual sync button                                    |
| Added    | Only one synchronisation per company at a time                            |
| Changed  | Invoice details are downloaded separately, newest first, and retried      |

### Added

- NAV rejects queries longer than 35 days, so the history is fetched in 30-day slices; progress is saved after every slice, so a failed or interrupted run continues where it stopped and never leaves a gap
- The "Sync now" button stops cleanly after about 20 seconds and reports that the sync is not finished; the unlimited run is meant for the command line or the daily job
- A second synchronisation of the same company is refused while one is running

### Changed

- The invoice details (lines, bank account) are downloaded in a separate phase, newest invoices first; failed invoices are retried on the next run, and the phase stops after 5 failures in a row (for example wrong credentials) instead of flooding NAV

## 0.1.0 — 2026-06-29

| Category | Description                                                   |
|----------|---------------------------------------------------------------|
| Added    | Initial release — NAV API v3.0 invoice sync, payment tracking, VAT report |

### Added

- NAV Online Számla API v3.0 client (`queryInvoiceDigest` + `queryInvoiceData`)
- Two-phase sync: paginated digest list → full invoice XML with line items and supplier bank account
- AES-256-GCM encrypted sign key storage in database
- Payment status tracking with manual paid/unpaid toggle and date recording
- Monthly VAT report grouped by tax rate, based on teljesítési dátum (completion date)
- Multi-currency support — original amounts and HUF equivalents stored separately
- CronAdmin-compatible `syncAll()` for automated daily sync
- Manual sync trigger via AJAX with button feedback
- Inbound/outbound invoice tabs with date, status, and partner filters
- Expandable invoice rows showing line item detail
