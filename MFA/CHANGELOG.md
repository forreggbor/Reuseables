# Changelog

All notable changes to MFAuthenticator will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
