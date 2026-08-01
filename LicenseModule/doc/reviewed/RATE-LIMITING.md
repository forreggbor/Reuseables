# License Server Rate Limiting (429 Handling)

## Overview

LicenseManager v2.8.0 replaced the file-based license-check rate limiter with a database-backed one that correctly returns `429 Too Many Requests` when a client exceeds the allowed validation frequency. The LicenseModule handles this response as a distinct, non-fatal condition.

## How It Differs from a Network Failure

| Condition            | Means                                      | Grace clock | What to do          |
|----------------------|--------------------------------------------|-------------|---------------------|
| `429 Too Many Requests` | Server reachable, license unchanged     | Not consumed | Back off, retry later |
| Network error / timeout | Server unreachable                     | Consumed     | Enter offline grace mode |
| `5xx` server error   | Server problem                             | Consumed     | Enter offline grace mode |

A `429` proves the server is up and the license has not changed. It would be wrong to treat it as an outage and start consuming the offline grace window.

## Module Behaviour on 429

`LicenseValidator::validate()` returns:

```php
[
    'success'   => false,
    'status'    => LicenseStatus::THROTTLED,  // 'throttled'
    'message'   => 'License server is temporarily rate-limiting this client; please retry later.',
    'throttled' => true,
]
```

- `last_check_at` is **not** updated, so the next scheduled check will retry sooner.
- The offline grace period clock is **not** advanced.
- No validation entry is written to `license_validation_history`.

## Application Integration

Check `throttled` before treating a failed validation as a hard error:

```php
$result = $licenseModule->validate($licenseKey, $domain);

if (!empty($result['throttled'])) {
    // Server is reachable but rate-limiting us — treat current cached status as authoritative
    $status = $licenseModule->getStatus();
    // Continue normally; do not show an error to the user
} elseif (!$result['success']) {
    // Real failure (offline, revoked, expired)
}
```

`getStatus()` reads the locally cached license status, which remains valid across throttled responses.

## Status Constant

`LicenseStatus::THROTTLED` (`'throttled'`) is not returned by the license server as a license state — it is a client-side signal produced only when the HTTP layer returns 429.

## Server Version Requirement

This behaviour requires LicenseManager **v2.8.0 or later**. Earlier servers used a file-based limiter that failed silently without returning 429; those servers behaved as if unlimited checks were allowed.
