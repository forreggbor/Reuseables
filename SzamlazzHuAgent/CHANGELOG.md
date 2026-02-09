# Changelog

All notable changes to SzamlazzHuAgent will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Summary

| Version | Date       | Summary                                                  |
|---------|------------|----------------------------------------------------------|
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
