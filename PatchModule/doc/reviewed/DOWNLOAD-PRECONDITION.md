# Patch Download Precondition: Recently-Verified Requirement

## Overview

The patch server (LicenseManager v2.8.0+) requires proof of a recent, successful license check before serving a patch file. This prevents credential relay: an attacker who intercepts a `license_key` cannot immediately download patches from a different machine without having recently verified that key themselves.

## How the Server Enforces It

Before streaming the patch file, the server checks whether the license key had a recent successful (`valid` or `grace`) check in its `license_checks` log. The logic differs by license type:

| License type     | Requirement                                                              |
|------------------|--------------------------------------------------------------------------|
| IP-bound         | A `valid`/`grace` check from the **same** client IP within the window   |
| Non-IP-bound     | Any `valid`/`grace` check from any IP within the window                 |

The window is configurable on the server (`patches.recent_check_window_days`, default **7 days**).

If the precondition is not met, the server returns:
```
HTTP 403
{"error": "license_key_not_recently_verified", ...}
```

## How PatchModule Handles It

### Normal Operation

Any client that runs scheduled license validation (the LicenseModule calls `/licenses/verify` periodically) will satisfy the precondition automatically. Most clients never see the 403.

### Auto-Retry via `license_verify_callback`

For first-time installs, long-idle systems, or clients that do not run periodic validation, the installer can trigger a license re-verification automatically when the 403 occurs:

```php
$module = new PatchModule([
    // ... other config ...
    'license_verify_callback' => function () use ($licenseModule): void {
        $licenseModule->validateLicense($licenseKey, $domain);
    },
]);
```

`PatchInstaller` invokes the callback once before download (proactive) and once more on a `not_recently_verified` 403 (reactive retry). The download is retried exactly once — there is no infinite loop.

### Error Surface

If the 403 persists after the retry (e.g., no callback configured, or the re-verification itself failed), `PatchInstaller::install()` returns a failure result with:

```php
[
    'success'    => false,
    'error'      => 'License must be verified before downloading',
    'error_code' => 'not_recently_verified',
]
```

The caller can inspect `error_code` to show a targeted message or trigger a manual re-verification flow.

## Compatibility with Older Servers

LicenseManager versions before v2.8.0 do not enforce this precondition and always serve the file. PatchModule also maps the legacy error code `license_key_ip_mismatch` (used in the initial unreleased v2.8.0 build) to `not_recently_verified`, so the retry logic works consistently.

## Interaction with IP-Bound Licenses

For IP-bound licenses (those with `licenses.ip_address` set on the server), the re-verification **must** come from the same IP that will perform the download. If the client is behind NAT or a load balancer with unstable IP assignment, the 403 may recur despite re-verification. In that case, consider removing IP binding from the license on the server side.

## Server Version Requirement

This precondition is enforced by LicenseManager **v2.8.0 or later**. It was fully corrected in the subsequent patch (fixing reverse-proxy IP resolution and extending the window from 5 minutes to 7 days).

## Compatibility

### Historical bug (PatchModule before v1.3.0)

The `extractErrorCode()` method in `PatchDownloader` (removed in v1.3.0) read `$decoded['error']`
directly and cast it to string. The server returns `error` as a nested object
`{"message": "<code>", "detail": "..."}`, so the cast always produced the literal string `"Array"`.

As a result, the `=== 'license_key_not_recently_verified'` comparison never matched, the
`license_verify_callback` was never triggered on a 403 response, and the auto-retry path described
above was effectively dead code in all versions before v1.3.0.

This was corrected in **v1.3.0** by replacing the broken extractor with `ServerErrorMapper::map()`,
which correctly reads `$decoded['error']['message']`.
