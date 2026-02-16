# Server-Side Grace Period

## Overview

The server-side grace period allows a license that has expired on the license server to remain fully operational for a configurable number of days. This gives users time to renew without immediate service disruption.

This is separate from the **offline grace period** (`GracePeriodManager`), which handles connectivity failures when the license server is unreachable.

## How It Works

### License Server Side

The license server determines grace period eligibility during validation:

1. Each license can have a per-license `grace_period_days` value (or `NULL` to use the global default)
2. When a license expires, the server checks if the current date is within `expiry_date + grace_period_days`
3. If within the window, the server returns `valid: true` with `status: "grace"`

### LicenseModule Client Side

When the module receives a grace period response:

1. `LicenseStatus::mapFromServer('grace', true)` maps to `LicenseStatus::GRACE`
2. The `grace_expires_at` date is stored in the `license_info` table
3. `isActive()` returns `true` for grace status (system operates normally)
4. `getCurrentStatus()` checks `grace_expires_at` locally — if the grace period has passed between server validations, it returns `EXPIRED`

### Status Flow

```
ACTIVE → license expires → server grants grace → GRACE → grace expires → EXPIRED
```

## API Response Format

When the license server returns a grace period response:

| Field              | Value                        |
|--------------------|------------------------------|
| `valid`            | `true`                       |
| `status`           | `"grace"`                    |
| `in_grace_period`  | `true`                       |
| `grace_expires_at` | `"2026-03-01 00:00:00"`      |

## Database Schema

The `license_info` table stores grace period data:

| Column             | Type          | Description                              |
|--------------------|---------------|------------------------------------------|
| `status`           | ENUM          | Includes `'grace'` value                 |
| `grace_expires_at` | DATETIME NULL | Server-provided grace period end date    |
| `grace_period_days`| INT           | Offline grace period (separate concept)  |

## Public API

| Method                           | Returns      | Description                                      |
|----------------------------------|--------------|--------------------------------------------------|
| `isInGracePeriod()`              | `bool`       | `true` when status is `GRACE`                    |
| `isActive()`                     | `bool`       | `true` for both `ACTIVE` and `GRACE`             |
| `getGraceExpiresAt()`            | `?string`    | Grace expiry datetime or `null`                  |
| `getDaysUntilGraceExpiration()`  | `?int`       | Days remaining in grace period or `null`          |

## Integration Example

```php
// Middleware: grace period passes through (isActive returns true)
$check = $license->checkMiddleware();
if ($check !== null) {
    http_response_code($check['http_code']);
    echo $check['view'];
    exit;
}

// Optional: show warning banner to users in grace period
if ($license->isInGracePeriod()) {
    $daysLeft = $license->getDaysUntilGraceExpiration();
    $expiresAt = $license->getGraceExpiresAt();
    // Display renewal warning with $daysLeft and $expiresAt
}
```

## Migration for Existing Installations

Run the following SQL to add grace period support to an existing `license_info` table:

```sql
ALTER TABLE license_info
    MODIFY COLUMN status ENUM('active', 'grace', 'expired', 'invalid', 'suspended') DEFAULT 'active',
    ADD COLUMN grace_expires_at DATETIME NULL COMMENT 'Server-side grace period expiry date' AFTER grace_period_days;

ALTER TABLE license_validation_history
    MODIFY COLUMN status ENUM('success', 'grace', 'expired', 'invalid', 'suspended', 'error') NOT NULL;
```

## Translations

| Key                     | EN                                                                                                          | HU                                                                                                                                   |
|-------------------------|-------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| `LICENSE_GRACE_TITLE`   | License Grace Period                                                                                        | Licenc türelmi időszak                                                                                                                |
| `LICENSE_GRACE_MESSAGE` | Your license has expired but is currently in a grace period. Please renew your license to avoid interruption.| A licenced lejárt, de jelenleg türelmi időszakban van. Kérjük, újítsd meg a licencedet a szolgáltatás megszakadásának elkerülése érdekében. |
| `LICENSE_GRACE_NOTICE`  | The system is fully operational during the grace period. Renew your license to ensure uninterrupted access.  | A rendszer a türelmi időszak alatt teljes mértékben működőképes. Újítsd meg a licencedet a zavartalan hozzáférés biztosításához.       |
