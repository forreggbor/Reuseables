# Changelog

All notable changes to MFAuthenticator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-03-03

| Category | Description                                                          |
|----------|----------------------------------------------------------------------|
| Fixed    | QR codes still unreadable after 1.0.1 due to missing version blocks |

### Fixed

- QR codes for versions 7 and above were still unreadable after the 1.0.1 rewrite because the required version information blocks were never written into the matrix. Without these 18-bit BCH-encoded blocks, scanners could not determine the QR version and rejected all codes. The version information placement is now correctly implemented per the QR spec.

## [1.0.1] - 2026-03-03

| Category | Description                                          |
|----------|------------------------------------------------------|
| Fixed    | QR codes were unreadable by all authenticator apps   |

### Fixed

- QR code generator produced invalid codes that no authenticator app (Microsoft Authenticator, Google Authenticator, Authy) could scan. The internal implementation was replaced with a correct port of Kazuhiko Arase's proven qrcode-generator library, fixing the format information placement, Reed-Solomon error correction, data placement, and mask penalty scoring.

## [1.0.0] - 2026-01-21

### Added

- Initial release
- `MFAuthenticator` class with RFC 6238 TOTP implementation
- `QRCode` class for pure PHP QR code generation (no dependencies)
- Cryptographic secret generation using `random_bytes()`
- Timing-safe code verification using `hash_equals()`
- Replay attack prevention with `verifyWithReplayProtection()`
- Backup code generation and Argon2id hashing
- Configurable parameters: issuer, digits, period, algorithm, tolerance
- Configuration validation for security (rejects insecure settings)
- Base32 encoding/decoding for secrets
- QR code output as PNG binary or base64 data URI
- Database schema template (`schema.sql`) with two integration options
- Comprehensive README with implementation guide
