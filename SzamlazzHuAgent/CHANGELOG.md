# Changelog

All notable changes to SzamlazzHuAgent will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Summary

| Version | Date       | Summary                                                  |
|---------|------------|----------------------------------------------------------|
| 1.7.0   | 2026-10-05 | Add continued fulfillment with a settlement period and the seller bank account to net-based invoicing |
| 1.6.0   | 2026-10-02 | Add taxpayer (tax number) lookup from NAV through Számlázz.hu |
| 1.5.0   | 2026-09-30 | Add net-based invoicing, lookup by external id, invoice PDF download |
| 1.4.1   | 2026-09-30 | Stop writing the Agent key to disk, fix invoice_prefix crash |
| 1.4.0   | 2026-08-01 | Add configurable e-invoice vs paper invoice type          |
| 1.3.0   | 2026-06-03 | Add per-line item comment field                                   |
| 1.2.0   | 2026-02-10 | Add payment_method_label, validate keys, deprecate config mapping |
| 1.1.8   | 2026-02-09 | Fix legacy cURL fallback consistency with InvoiceBuilder |
| 1.1.7   | 2026-02-06 | Fix payment due date calculation base date               |
| 1.1.6   | 2026-02-06 | Add explicit paid status parameter                       |
| 1.1.5   | 2026-02-06 | Fix currency handling, align with SDK Ft default         |
| 1.1.4   | 2026-02-05 | Add send_email parameter to buyer data                   |
| 1.1.3   | 2026-01-23 | Fix Hungarian character encoding in receipt JSON         |
| 1.1.2   | 2026-01-18 | Configurable SDK storage path                            |
| 1.1.1   | 2026-01-18 | Fix reverse invoice, add InvoiceResult getters           |
| 1.1.0   | 2026-01-18 | Add delivery note, proforma invoice, receipt support     |
| 1.0.1   | 2026-01-18 | Fix cURL XML typos, add OTP Simple and Cheque methods    |
| 1.0.0   | 2025-01-18 | Initial release with invoice generation and SDK/cURL     |

## [1.7.0] - 2026-10-05

| Category | Description |
|----------|-------------|
| Added    | A net-based invoice can be a continued-fulfillment invoice with a settlement period (closes #49) |
| Added    | A net-based invoice can carry the seller's bank account and bank name (closes #51) |

### Added

- `issueInvoice()` (and its preview) accepts the optional header keys `continued_fulfillment`, `settlement_from` and `settlement_to`: with the flag on, both dates are required (real dates, from not after to) and go out as the buyer ledger block (`folyamatosTelj`, `elszDatumTol`, `elszDatumIg`) and as the same period on every item. Dates without the flag, or a missing, invalid or reversed date, are refused before anything is sent. The module does not check the fulfillment date against the period (closes #49).
- `issueInvoice()` accepts the optional header keys `bank_account` and `bank_name`: they go out as the seller block (`bank`, `bankszamlaszam`); without them nothing is sent and Számlázz.hu prints the default account of its own settings. A `bank_name` without an account is refused before anything is sent. The module does not validate the account number (closes #51).
- Nothing changes for callers that do not pass these keys: the other invoice, storno, delivery note, proforma and receipt methods are untouched.

## [1.6.0] - 2026-10-02

| Category | Description |
|----------|-------------|
| Added    | A Hungarian tax number can be looked up from NAV through Számlázz.hu with just the Agent key (closes #48) |

### Added

- `queryTaxpayer()` looks up a Hungarian taxpayer by tax number (first 8 digits) through the Számlázz.hu Agent taxpayer interface, which asks NAV Online Számla: no NAV technical user is needed, only the Agent key already used for invoicing. The result tells whether NAV answered, whether it knows the tax number, and returns the name, short name (when NAV has one), tax number details (VAT code, county code), business type and addresses; an unknown tax number is a normal "not found" answer, not an error. NAV may leave any of these out, so every part except the name and tax id must be treated as optional.
- The lookup uses its own agent and writes no files (the answer contains personal data of sole proprietors), so it never affects invoicing calls in the same process.

## [1.5.0] - 2026-09-30

| Category | Description |
|----------|-------------|
| Added    | Invoices can be issued from exact net amounts, taken over unchanged from the source document (closes #47) |
| Added    | An issued invoice can be looked up by its external reference to tell whether it already exists (closes #47) |
| Added    | The PDF of any invoice in the account can be downloaded by invoice number (closes #47) |
| Added    | Results now report an "uncertain" outcome when the request was sent but the answer was lost (closes #47) |

### Added

- Invoices can be issued from exact net amounts: every date, amount, VAT code, discount note and foreign-currency rate is sent exactly as given, so the invoice matches its source document to the cent. Missing or invalid values stop the request before anything is sent. A preview-only mode returns the preview PDF without creating an invoice.
- An invoice issued with an external reference can be looked up later. A "not found" answer is only trusted when Számlázz.hu explicitly says so; a network error or any other answer is reported as uncertain, so a duplicate invoice is never created by mistake.
- The PDF of any invoice in the account can be downloaded by its invoice number.
- Every result now distinguishes three outcomes: issued, refused (nothing was created), and uncertain (the invoice may exist — check before sending again).
- None of the new operations write request or response files (which contain the Agent key) or PDF copies to disk.

## [1.4.1] - 2026-09-30

| Category | Description |
|----------|-------------|
| Security | Request XML files (containing the Agent key) are no longer saved to disk (closes #43) |
| Fixed    | Invoice, delivery note and proforma generation no longer fail when `invoice_prefix` is configured (closes #44) |

### Security

- `getAgent()` now disables request XML saving (`setRequestXmlFileSave(false)`) for every SDK call. The SDK saved each request XML under `<storage_path>/xmls` by default, and that XML contains the Agent key (`<szamlaagentkulcs>`) in plaintext. Existing files from earlier versions are not removed — delete them from `<storage_path>/xmls` on each installation and consider rotating the key.
- Known and not fixed here: the vendored SDK enables `CURLOPT_VERBOSE` (`SzamlaAgentRequest.php:483`), so the curl trace (including the `Cookie: JSESSIONID` header, not the key) still goes to the web server's stderr. It requires patching or upgrading the vendored SDK (tracked in #46).

### Fixed

- `invoice_prefix` config key called a non-existent SDK method (`setInvoiceNumberPrefix()`), so `generateInvoice()`, `generateDeliveryNote()` and `generateProforma()` failed with "Call to undefined method" whenever a prefix was configured. The builder now uses the SDK's `setPrefix()`.

## [1.4.0] - 2026-08-01

| Category | Description |
|----------|-------------|
| Added    | `e_invoice` order data key selects electronic vs paper invoice type |
| Added    | `eInvoice` parameter on `createStornoInvoice()` to mirror the original invoice's type |
| Changed  | Invoices are paper by default instead of always electronic |

### Added

- `e_invoice` key in `$orderData` (SDK path via `InvoiceBuilder::build()`) — `true` generates an electronic invoice, `false` (default) generates a paper invoice
- `eInvoice` parameter on `SzamlazzHuAgent::createStornoInvoice()` and `InvoiceBuilder::buildReverseInvoice()` — controls whether the storno invoice is electronic; should mirror the original invoice's type
- Legacy cURL fallback (`buildInvoiceXml()`) now honors the same `e_invoice` order data key for the `<eszamla>` XML flag

### Changed

- **Default invoice type changed from electronic to paper** — both the SDK path (`InvoiceBuilder::build()`) and the legacy cURL fallback (`buildInvoiceXml()`) previously always generated an electronic invoice regardless of `$orderData`. They now default to a paper invoice unless `'e_invoice' => true` is explicitly set. Existing integrations that rely on invoices always being electronic must add `'e_invoice' => true` to `$orderData`.

## [1.3.0] - 2026-06-03

| Category | Description |
|----------|-------------|
| Added    | Per-line item comment field |

### Added

- `comment` field on invoice items — displayed as a per-line note on the invoice (`<megjegyzes>` XML tag); supported in both the SDK path (`InvoiceBuilder`) and the cURL fallback

## [1.2.0] - 2026-02-10

### Added

- `payment_method_label` parameter in `$orderData` allows custom display text on invoices while keeping correct payment behavior (e.g. "Átutalás 15 napon belül")
- Payment method key validation — unknown keys now throw `\InvalidArgumentException` instead of silently defaulting to bank transfer

### Changed

- Internal payment logic now uses the English key (`cash`) instead of the Hungarian label (`Készpénz`) for behavior decisions (auto-paid, deadline)

### Deprecated

- `payment_methods` config option — use `payment_method_label` in `$orderData` instead. Legacy config still works but logs a deprecation warning.

## [1.1.8] - 2026-02-09

### Fixed

- Legacy cURL fallback (`buildInvoiceXml`) payment due date now calculated from issue date instead of fulfillment date
- Legacy cURL fallback now respects `payment_deadline_days` from order data instead of hardcoded 8 days
- Legacy cURL fallback now sets payment due date to issue date for cash (`Készpénz`) payments
- Legacy cURL fallback currency now normalized through `mapCurrencyString()` method (e.g. `HUF` → `Ft`)
- Legacy cURL fallback now respects per-invoice `language` from order data instead of only reading global config

## [1.1.7] - 2026-02-06

### Fixed

- Payment due date (`setPaymentDue`) now calculated from issue date instead of fulfillment date for both invoice and proforma headers

## [1.1.6] - 2026-02-06

### Added

- Added `paid` parameter to `$orderData` for explicitly setting invoice paid status
- Supported in both SDK and cURL fallback code paths

## [1.1.5] - 2026-02-06

### Changed

- Currency default changed from `HUF` to `Ft` to align with SzamlaAgent SDK default (`Currency::CURRENCY_FT`)
- `mapCurrency()` now maps both `HUF` and `FT` to `CURRENCY_FT`, and uses `CURRENCY_FT` as default
- Receipt currency now routed through `mapCurrency()` for consistent handling across all document types

### Fixed

- cURL fallback XML currency now uses order-level `$orderData['currency']` instead of global `$this->config['currency']`
- Receipt exchange rate check now correctly recognizes both `HUF` and `FT` as domestic currency

## [1.1.4] - 2026-02-05

### Added

- Added `send_email` parameter to buyer data to control email notification after document generation

## [1.1.3] - 2026-01-23

### Fixed

- Added JSON_UNESCAPED_UNICODE flag to receipt data JSON encoding for proper Hungarian character display

## [1.1.2] - 2026-01-18

### Changed

- SDK storage path now configurable via `storage_path` config option
- PDFs stored in `storage_path/pdf/` subdirectory
- SDK files (logs, cookies, xmls) stored in `storage_path/` subdirectories

## [1.1.1] - 2026-01-18

### Fixed

- Fixed `buildReverseInvoice()` calling undefined `setEInvoice()` method

### Added

- Added getter methods to `InvoiceResult`: `isSuccess()`, `getErrorMessage()`, `getErrorCode()`, `getDocumentNumber()`, `getInvoiceNumber()`, `getPdfPath()`, `getPdfContent()`

## [1.1.0] - 2026-01-18

### Added

- **Delivery Note support**: `generateDeliveryNote()` for szállítólevél
- **Proforma Invoice support**:
  - `generateProforma()` for díjbekérő creation
  - `deleteProforma()` to delete by document number
  - `deleteProformaByOrderNumber()` to delete by order number
- **Receipt support**:
  - `generateReceipt()` for nyugta creation with configurable options
  - `getReceiptPdf()` to retrieve receipt PDF
  - `getReceiptData()` to retrieve receipt data as JSON
  - `sendReceipt()` to send receipt via email
  - `createReverseReceipt()` for storno receipt
- Document building methods in `InvoiceBuilder` for all new document types

## [1.0.1] - 2026-01-18

### Fixed

- Fixed XML typos in cURL fallback (`<beallitasok>`, `<szamlaLetoltes>`)

### Added

- Added OTP Simple (`otp_simple`) payment method
- Added Cheque (`cheque`) payment method

## [1.0.0] - 2025-01-18

### Added

- Initial release extracted from FlowerShop invoicing system
- `SzamlazzHuAgent` main facade with configuration
- `InvoiceBuilder` for creating SDK invoice objects from data arrays
- `InvoiceResult` return object for all operations
- `FileSystemStorage` adapter for PDF storage
- `StorageInterface` for custom storage implementations
- Invoice generation via official szamlaagent SDK
- Invoice preview generation (SDK only)
- Storno/reverse invoice creation (SDK only)
- cURL fallback for basic invoice generation
- Connection validation
- Configurable payment method mapping
- Support for HUF, EUR, USD currencies
- Hungarian and English language support
- Comprehensive README with integration examples
