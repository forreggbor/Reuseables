# Changelog

All notable changes to Virtualjog will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Summary

| Version | Date       | Summary                                                      |
|---------|------------|--------------------------------------------------------------|
| 1.0.0   | 2026-02-16 | Initial release — pure PHP client extracted from WP plugin   |

## [1.0.0] - 2026-02-16

### Added

- `VirtualjogClient` main facade class with configuration via constructor array
- `StorageInterface` contract for framework-agnostic key-value persistence
- `SessionStorage` default adapter using PHP sessions
- `ApiClient` internal cURL-based HTTP client for Virtualjog API communication
- `ApiResult` immutable result object with readonly properties and factory methods
- `CookieConsentManager` for managing cookie consent state (stat, marketing, other)
- API authentication via access token (`authorize()`, `isAuthorized()`, `logout()`)
- Legal document fetching with configurable 24-hour persistent cache
- Document type mapping (type key to document slug) for template integration
- Document iframe embed HTML generation (`getDocumentEmbedHtml()`, `getDocumentEmbedHtmlByType()`)
- Cookie consent module management (`enableCookieModule()`, `disableCookieModule()`)
- Cookie script HTML retrieval for page `<head>` injection
- Domain validation against allowed domains list
- Package/subscription status checking
- PSR-4 compatible autoloader
- Consent cookie management with configurable lifetime, path, secure, and SameSite attributes
- Script handle checking against known provider lists (`isScriptAllowed()`)
- JSON consent request processing from `php://input` (`processConsentRequest()`)
