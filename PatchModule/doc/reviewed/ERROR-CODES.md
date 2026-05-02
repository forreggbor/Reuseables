# PatchModule Error Codes

Every `error_code` value that can appear in the result arrays returned by `PatchInstaller::install()`,
`PatchChecker::checkForUpdates()`, and `PatchDownloader::download()` is listed here, along with the
server-side condition that produces it and the recommended user-facing message key.

## Error Code Reference

| `error_code`              | HTTP status | Server condition                                             | Translation key                               |
|---------------------------|-------------|--------------------------------------------------------------|-----------------------------------------------|
| `not_recently_verified`   | 403         | `error.message` = `license_key_not_recently_verified` or `license_key_ip_mismatch` | `TEXT_PATCH_ERROR_NOT_RECENTLY_VERIFIED` |
| `invalid_license`         | 403         | `error.message` = `Invalid license`                         | `TEXT_PATCH_ERROR_INVALID_LICENSE`            |
| `license_revoked`         | 403         | `error.message` = `License not valid`, detail = `TEXT_API_LICENSE_REVOKED` | `TEXT_PATCH_ERROR_LICENSE_REVOKED` |
| `license_expired`         | 403         | `error.message` = `License not valid`, detail = `TEXT_API_LICENSE_EXPIRED` or `TEXT_API_LICENSE_GRACE_PERIOD` | `TEXT_PATCH_ERROR_LICENSE_EXPIRED` |
| `license_ip_mismatch`     | 403         | `error.message` = `License not valid`, detail = `TEXT_API_IP_MISMATCH` | `TEXT_PATCH_ERROR_LICENSE_IP_MISMATCH` |
| `package_mismatch`        | 403         | `error.message` = `Package mismatch`                        | `TEXT_PATCH_ERROR_PACKAGE_MISMATCH`           |
| `rate_limited`            | 429         | Router per-IP cap (60/min) or per-license-key download cap (10/hr) | `TEXT_PATCH_ERROR_RATE_LIMITED`          |
| `signing_unavailable`     | 503         | `error.message` = `signing_unavailable` — patch signing key missing on server | `TEXT_PATCH_ERROR_SIGNING_UNAVAILABLE` |
| `server_error`            | 5xx (other) | Unexpected server-side failure                               | `TEXT_PATCH_ERROR_SERVER_ERROR`               |
| `network_error`           | 0 / timeout | Could not reach the patch server                             | `TEXT_PATCH_ERROR_NETWORK_ERROR`              |
| `invalid_archive`         | —           | Extracted archive contained a symlink (path-traversal guard) | `TEXT_PATCH_ERROR_INVALID_ARCHIVE`           |
| `invalid_manifest_path`   | —           | Manifest contained a path with `..`, absolute path, or backslash | `TEXT_PATCH_ERROR_INVALID_MANIFEST_PATH` |

## Notes on `rate_limited`

There are two independent rate-limit sources on the server:

- **Router-level per-IP cap** (`api.rate_limit_per_minute`, default 60/min) — applies to every API
  endpoint including `/patches/check`. Returns HTTP 429 with no `Retry-After` header.
- **Per-license-key download cap** (`patches.download_rate_limit`, default 10/hr) — applies only to
  `/patches/{id}/download`. Returns HTTP 429 with `Retry-After: 3600`.

Both map to `error_code = 'rate_limited'`. When `retry_after` is non-null in the result, it contains
the number of seconds from the `Retry-After` header; when null, the caller should impose its own
back-off (suggested: 60 seconds).

## Notes on `not_recently_verified`

The installer calls the optional `license_verify_callback` proactively before every download, and
again reactively on a `not_recently_verified` response. The retry happens exactly once. If the 403
persists after the retry, the install fails with this code. See `DOWNLOAD-PRECONDITION.md` for full
details.

## Notes on grace period

The server returns `TEXT_API_LICENSE_GRACE_PERIOD` (in `error.detail`) when a license is past its
expiry but still within the configured grace window. From the client's perspective this is treated the
same as `license_expired` — the user must renew before updates can continue.

## `retry_after` field

`PatchInstaller::install()` and `PatchDownloader::download()` include a `retry_after` key in their
result arrays:

```php
[
    'success'     => false,
    'error'       => '...',
    'error_code'  => 'rate_limited',
    'retry_after' => 3600,   // seconds, or null if unknown
]
```

The caller is responsible for scheduling a retry after the indicated delay. PatchModule does not retry
automatically on `rate_limited`.
