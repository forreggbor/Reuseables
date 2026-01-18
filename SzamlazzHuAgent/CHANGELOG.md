# Changelog

All notable changes to SzamlazzHuAgent will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
