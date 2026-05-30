# PatchModule v1.8.0 — HTTP Wire Format

Frozen request/response contract for all 10 admin endpoints. Any change to this
document must be accompanied by matching changes in both `src/AdminActions.php`
and `js/patch-update.js`.

---

## Common conventions

- All requests and responses use `Content-Type: application/json`.
- All POST requests must include `X-CSRF-Token: <token>` header.
- The host's thin controller reads the HTTP status from `$result['status']` and
  sends `$result['data']` as the JSON body.
- Error responses always include `"success": false` and an `"error"` string.
  Some errors also include an `"error_code"` string (see Error codes below).
- The base URL (`PATCH_BASE_URL`) is set by the host and configured via the
  `data-base-url` attribute on the `#patch-mount` or `#patchUpdateBanner`
  element.

---

## Endpoints

### 1. `GET {base}/details`

Returns all available patches as JSON (same data set as the index page). The
host's index controller action must detect `Accept: application/json` and return
the data array directly when the header is present.

**Auth:** sysadmin  
**CSRF:** no

**Response 200**
```json
{
  "available": true,
  "patches": [
    {
      "id": 12,
      "version": "1.2.0",
      "released_at": "2026-04-01T00:00:00Z",
      "file_size": 524288,
      "release_notes": "Bug fixes and performance improvements."
    }
  ],
  "count": 1,
  "current_version": "1.1.3"
}
```

When no patches are available:
```json
{ "available": false, "patches": [], "count": 0, "current_version": "1.1.3" }
```

**Response 403** — not sysadmin
```json
{ "success": false, "error": "..." }
```

---

### 2. `GET {base}/details/{id}`

Returns the file manifest and metadata for a specific `patch_history` record.

**Auth:** sysadmin  
**CSRF:** no

**Response 200**
```json
{
  "id": 12,
  "version": "1.2.0",
  "previous_version": "1.1.3",
  "status": "completed",
  "installed_at": "2026-04-02T14:30:00Z",
  "installed_by": 5,
  "release_notes": "Bug fixes and performance improvements.",
  "release_notes_html": "<p>Bug fixes and performance improvements.</p>",
  "is_manual_upload": false,
  "files": [
    { "path": "app/controllers/FooController.php", "action": "modified" },
    { "path": "app/views/bar.php", "action": "added" },
    { "path": "public/js/old-script.js", "action": "deleted" }
  ]
}
```

**Response 403** — not sysadmin
```json
{ "success": false, "error": "..." }
```

**Response 404** — record not found
```json
{ "success": false, "error": "..." }
```

---

### 3. `POST {base}/check`

Forces a server-side update check (bypasses the cache) and returns the current
list of available patches.

**Auth:** sysadmin  
**CSRF:** yes

**Request body** — empty JSON object `{}`

**Response 200**
```json
{
  "success": true,
  "available": true,
  "count": 2,
  "patches": [
    { "id": 12, "version": "1.2.0", "released_at": "...", "file_size": 524288 },
    { "id": 13, "version": "1.3.0", "released_at": "...", "file_size": 102400 }
  ],
  "csrf_token": "new-csrf-token-value"
}
```

**Response 403** — not sysadmin or invalid CSRF
```json
{ "success": false, "error": "..." }
```

---

### 4. `POST {base}/dismiss`

Dismisses the update notification for a specific version.

**Auth:** sysadmin  
**CSRF:** yes

**Request body**
```json
{ "version": "1.2.0" }
```

**Response 200**
```json
{ "success": true, "csrf_token": "new-csrf-token-value" }
```

**Response 403** — not sysadmin or invalid CSRF
```json
{ "success": false, "error": "..." }
```

---

### 5. `POST {base}/dismiss-all`

Dismisses all pending update notifications.

**Auth:** sysadmin  
**CSRF:** yes

**Request body** — empty JSON object `{}`

**Response 200**
```json
{ "success": true, "csrf_token": "new-csrf-token-value" }
```

**Response 403** — not sysadmin or invalid CSRF
```json
{ "success": false, "error": "..." }
```

---

### 6. `POST {base}/verify-password`

Verifies the current sysadmin's password and issues a one-time install
authorization token. The token must be submitted with the subsequent
`install` request.

The host **must throttle** this endpoint (e.g. 5 attempts per minute per IP).
See `doc/INTEGRATION-GUIDE.md` for a sample middleware snippet.

**Auth:** sysadmin  
**CSRF:** yes

**Request body**
```json
{ "password": "plaintext-password" }
```

**Response 200** — password correct
```json
{
  "success": true,
  "install_token": "a3f8e2c1...",
  "csrf_token": "new-csrf-token-value"
}
```

`csrf_token` is a refreshed CSRF token; the client must update its stored token
before the next request.

**Response 400** — empty password
```json
{ "success": false, "error": "Password is required." }
```

**Response 401** — wrong password
```json
{ "success": false, "error": "Incorrect password." }
```

**Response 403** — not sysadmin or invalid CSRF
```json
{ "success": false, "error": "..." }
```

---

### 7. `POST {base}/install`

Installs a specific patch. Acquires an exclusive file lock to prevent
concurrent installs. Long-running — the PHP process calls `set_time_limit(0)`
and `ignore_user_abort(true)`.

The client generates the `progress_token` **before** sending this request and
starts polling `{base}/progress?token=…` immediately (before the response
arrives).

**Auth:** sysadmin + valid install authorization token  
**CSRF:** yes

**Request body**
```json
{
  "patch_history_id": 12,
  "install_token": "a3f8e2c1...",
  "create_backup": true,
  "progress_token": "b4d9f1a2c3e5f6a7b8c9d0e1f2a3b4c5"
}
```

- `patch_history_id` — `id` from the patch record (integer, > 0)
- `install_token` — one-time token from `verify-password` (consumed on use)
- `create_backup` — boolean; backup is created only on the first patch in a queue
- `progress_token` — 32-char lowercase hex string generated by `crypto.getRandomValues()`

**Response 200** — install succeeded
```json
{
  "success": true,
  "has_next": true,
  "next_version": "1.3.0",
  "next_install_token": "c5e8d2f1...",
  "csrf_token": "new-csrf-token-value"
}
```

When `has_next` is `false`:
```json
{
  "success": true,
  "has_next": false,
  "next_version": null,
  "next_install_token": null,
  "csrf_token": "new-csrf-token-value"
}
```

`next_install_token` is a fresh install authorization token (TTL: 24 h)
issued only when `has_next` is `true`, so the user does not need to re-enter
their password to install the next queued patch.

**Response 400** — invalid `patch_history_id` or `progress_token`
```json
{ "success": false, "error": "..." }
```

**Response 403** — not sysadmin, invalid CSRF, or expired/invalid install token
```json
{
  "success": false,
  "error_code": "not_recently_verified",
  "error": "Password confirmation has expired. Please try again."
}
```

**Response 409** — another install is already in progress
```json
{
  "success": false,
  "error_code": "install_in_progress",
  "error": "Another update is already in progress. Please wait and try again."
}
```

**Response 500** — install failed
```json
{
  "success": false,
  "error_code": "signature_verification_failed",
  "error": "Update verification failed. The changes have been rolled back."
}
```

---

### 8. `GET {base}/progress?token={token}`

Polls the progress of an ongoing installation. The file is created atomically
by `ProgressTracker`; polling before the install starts returns 404 (normal —
the client retries at 1500 ms intervals).

**Auth:** token regex `^[a-f0-9]{32}$` only (no session check required)  
**CSRF:** no

**Response 200**
```json
{
  "steps": [
    { "id": "preflight_checks",  "status": "completed" },
    { "id": "create_backup",     "status": "completed" },
    { "id": "download_patch",    "status": "active"    },
    { "id": "extract_patch",     "status": "pending"   },
    { "id": "execute_migration", "status": "pending"   },
    { "id": "copy_files",        "status": "pending"   },
    { "id": "update_version",    "status": "pending"   },
    { "id": "verify_installation","status": "pending"  },
    { "id": "cleanup",           "status": "pending"   }
  ]
}
```

Step status values: `pending` | `active` | `completed` | `failed`

**Response 400** — token does not match `^[a-f0-9]{32}$`
```json
{ "success": false, "error": "..." }
```

**Response 404** — progress file not yet created or already deleted
```json
{ "success": false, "error": "..." }
```

---

### 9. `POST {base}/rollback`

Rolls back an installed patch by restoring its file snapshot and database
backup. Acquires the same exclusive file lock as `install`.

**Auth:** sysadmin  
**CSRF:** yes

**Request body**
```json
{ "id": 12 }
```

**Response 200**
```json
{ "success": true, "csrf_token": "new-csrf-token-value" }
```

**Response 400** — invalid id
```json
{ "success": false, "error": "..." }
```

**Response 403** — not sysadmin or invalid CSRF
```json
{ "success": false, "error": "..." }
```

**Response 409** — another install/rollback is in progress
```json
{
  "success": false,
  "error_code": "install_in_progress",
  "error": "Another update is already in progress. Please wait and try again."
}
```

**Response 500** — rollback failed
```json
{ "success": false, "error": "..." }
```

---

### 10. `POST {base}/upload`

Accepts a manually supplied patch archive (`.tgz` only), extracts the manifest
to validate the version, and stores the staged archive for installation via the
existing `POST {base}/install` endpoint. Trust gate: sysadmin auth + CSRF.

**Auth:** sysadmin  
**CSRF:** yes (field in multipart form body)  
**Content-Type:** `multipart/form-data`

**Request fields**
- `patch_file` — the `.tgz` patch archive (≤ `max_upload_size`, default 100 MB)
- `csrf_token` — CSRF token value

**Response 200**
```json
{
  "success": true,
  "patch_history_id": 42,
  "version": "1.7.0",
  "release_notes": "Bug fixes",
  "release_notes_html": "<p>Bug fixes</p>",
  "file_size": 2456789,
  "sha256": "ab12cd34...",
  "warning": null,
  "warning_message": null,
  "csrf_token": "new-rotated-value"
}
```

When a version gap is detected (`warning = "version_gap"`), the upload still
succeeds and `warning_message` contains a human-readable confirmation prompt.
The client must show the message and allow the user to abort before proceeding
to install.

**Response 400** — oversized file or unsupported MIME type
```json
{ "success": false, "error_code": "upload_too_large", "error": "..." }
{ "success": false, "error_code": "upload_invalid_mime", "error": "..." }
```

**Response 403** — auth or CSRF failure
```json
{ "success": false, "error": "..." }
```

**Response 409** — version policy violation
```json
{ "success": false, "error_code": "upload_version_downgrade", "error": "..." }
{ "success": false, "error_code": "upload_version_already_installed", "error": "..." }
```

**Response 422** — archive or manifest invalid
```json
{ "success": false, "error_code": "upload_invalid_archive", "error": "..." }
{ "success": false, "error_code": "upload_invalid_manifest", "error": "..." }
```

**Response 500** — storage failure or upload lock timeout
```json
{ "success": false, "error_code": "upload_failed", "error": "..." }
```

---

## Error codes

Used in `error_code` fields of error responses. Mapped to human-readable strings
via the `data-error-labels` attribute on the mount element.

| Code | Meaning |
|------|---------|
| `not_recently_verified` | Install authorization token expired or invalid |
| `invalid_license` | License key is not valid for this product |
| `license_revoked` | License has been revoked |
| `license_expired` | License has expired |
| `license_ip_mismatch` | Install not allowed from this IP address |
| `package_mismatch` | Update not compatible with installed package |
| `rate_limited` | Too many requests to the update server |
| `signing_unavailable` | Signing service temporarily unavailable |
| `server_error` | Update server or local server error |
| `network_error` | Could not connect to the update server |
| `invalid_archive` | Update archive contains invalid files |
| `invalid_manifest_path` | Update archive contains an unsafe file path |
| `invalid_manifest_schema` | Update archive has an invalid format |
| `install_in_progress` | Concurrent install/rollback attempted |
| `verification_failed` | Post-install verification failed; changes rolled back |
| `upload_failed` | Upload storage failure or lock timeout |
| `upload_invalid_archive` | Uploaded file is not a valid patch archive |
| `upload_invalid_manifest` | Uploaded archive has no valid manifest |
| `upload_invalid_mime` | Uploaded file is not a .tgz archive |
| `upload_too_large` | Uploaded file exceeds the configured size limit |
| `upload_version_already_installed` | Uploaded version is already installed |
| `upload_version_downgrade` | Uploaded version is older than the current version |

---

## Archive migration layout (v1.8.0)

**Breaking change in v1.8.0:** The legacy `migration.sql` file at archive root and the `has_migration` boolean in `manifest.json` are removed. The new format uses a `migrations/` directory and an always-present `migrations` array.

### Archive layout

```
patch-X.Y.Z.tgz
├── manifest.json
├── release_notes.md
├── migrations/                 # omitted when the patch has no SQL migrations
│   ├── 2026_05_11_151403_create_foo.sql
│   └── 2026_05_11_151503_add_bar.sql
└── files/
    └── ...
```

### manifest.json (v1.8.0)

```json
{
  "version": "X.Y.Z",
  "migrations": [
    "2026_05_11_151403_create_foo.sql",
    "2026_05_11_151503_add_bar.sql"
  ],
  "files": [ ... ],
  "removed_files": [ ... ]
}
```

- `migrations[]` is **always present** (empty array = no SQL migrations).
- `has_migration` (boolean) is **removed** — `count(migrations) > 0` is the signal.
- `removed_files` is omitted when empty (unchanged behaviour).
- Deletions of files under `database/migrations/` are **not** added to `removed_files` — removing a migration from the source tree is a no-op in the wire format.
- After all listed files are removed, PatchModule prunes any directories that became empty (bottom-up). Directories that still contain prod-only content are left untouched.

### Authority rule

The `migrations/` directory on disk is the source of truth. If `manifest.migrations[]` and the on-disk listing disagree, PatchInstaller logs WARN and proceeds from the on-disk listing.

### Execution order

Files are sorted lexicographically (`SORT_STRING`). The `YYYY_MM_DD_HHMMSS_` prefix gives chronological order.

### The `execute_migration` step ID is frozen

The progress-tracker step ID `execute_migration` is unchanged. Its internal behaviour changed (multi-file directory execution instead of single-file), but the progress wire format is identical.
