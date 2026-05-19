# AUTO_UPLOAD — Automatic Patch Upload to LicenseManager

Implementation specification for integrating automatic patch upload into PatchCreator.
**This document describes the client side only.** The server-side endpoint contract is
implemented in LicenseManager ≥ v2.16.1 and documented in
`doc/PATCH_MANAGEMENT.md#api-upload` and `doc/API_UPLOAD_TOKENS.md`.

---

## Overview

After a successful build, PatchCreator can POST the generated `.tgz` and its SHA-256
directly to LicenseManager's upload API. This eliminates the manual admin-panel step
and makes the release end-to-end automated from a single `PatchCreator.sh -y` run.

The upload step is **opt-in** — existing invocations without config behave identically
to today. Backward compatibility is not broken.

---

## Prerequisites

Add `curl` to the dependency list alongside the existing `jq`, `git`, `tar`,
`sha256sum`, `grep -P`.

---

## Configuration Sources (precedence order)

Configuration is resolved in the following order. The first source that provides
both `upload_url` and `token` wins.

### 1. Environment variables (CI runners)

```bash
export PATCHCREATOR_UPLOAD_URL="https://your-licensemanager.example.com/api/v1/patches/upload"
export PATCHCREATOR_TOKEN="lcmu_your_token_here"
```

### 2. `.patchcreator.local` (developer workstation)

A JSON file in the project root. Must be gitignored — see [Security](#security).

```json
{
  "upload_url": "https://your-licensemanager.example.com/api/v1/patches/upload",
  "token": "lcmu_your_token_here"
}
```

**Invalid JSON must fail loudly** — never silently fall back to "no upload":

```bash
if ! CONFIG=$(jq '.' "$PROJECT_ROOT/.patchcreator.local" 2>/dev/null); then
    echo "ERROR: .patchcreator.local contains invalid JSON — aborting"
    exit 1
fi
UPLOAD_URL=$(echo "$CONFIG" | jq -r '.upload_url // empty')
TOKEN=$(echo "$CONFIG" | jq -r '.token // empty')
```

### 3. Neither source present → skip upload

If neither `PATCHCREATOR_UPLOAD_URL`/`PATCHCREATOR_TOKEN` nor `.patchcreator.local`
provides both values, the upload step is silently skipped. The rest of the build
completes normally.

---

## CLI Flags

| Flag | Behaviour |
|------|-----------|
| `--upload` | Force upload on, even if no config is present (fails if config is also absent) |
| `--no-upload` | Force upload off, overrides any config source |

Default: upload if a config source is present **and** `--no-upload` is not set.

---

## Upload Command

Execute after both `.tgz` and `.tgz.sha256` exist (post-build, before the final
summary):

```bash
curl --fail --show-error --silent \
     --max-time 600 \
     -H "Authorization: Bearer $TOKEN" \
     -H "Expect:" \
     -F "patch_file=@$TGZ_PATH" \
     -F "sha256=$SHA256" \
     "$UPLOAD_URL"
```

**`-H "Expect:"`** is intentional. Without it, `curl` sends an `Expect: 100-continue`
header for large multipart bodies. Apache holds the upload body until the server
acknowledges with `100 Continue` — and under certain configurations this handshake
stalls indefinitely for chunked multipart requests. Suppressing `Expect` forces
immediate body send.

Do not add `-k` / `--insecure`. Require `https://` scheme; fail fast on `http://`:

```bash
if [[ "$UPLOAD_URL" != https://* ]]; then
    echo "ERROR: UPLOAD_URL must use https:// — got: $UPLOAD_URL"
    exit 1
fi
```

---

## Response Handling

**Always check `Content-Type` before JSON-parsing.** Apache may return a plain-HTML
413 (`LimitRequestBody` exceeded) or a proxy error page. If `Content-Type` does not
start with `application/json`, the body is not parseable as the API envelope:

```bash
CONTENT_TYPE=$(curl ... -w '\n%{content_type}' ...)
# Split body and Content-Type, then:
if [[ "$CONTENT_TYPE" != application/json* ]]; then
    echo "ERROR: Server returned non-JSON response (HTTP $HTTP_CODE)"
    echo "Body: ${BODY:0:200}"
    exit 1
fi
```

### Status codes

| HTTP code | Action |
|-----------|--------|
| **201** | Success — log version and exit 0 |
| **409** | Parse JSON; lowercase both SHAs. If `data.sha256 == local_sha256` → log "already uploaded" and **exit 0** (idempotent retry). If different → log "version exists with different content — manual intervention required" and **exit non-zero**. |
| **429** | Honor `Retry-After` header (or wait 60 s if absent). Single retry, then exit non-zero. |
| **403** | TLS required — server rejects non-HTTPS. Exit non-zero; no retry. |
| **422** | Validation failure — log `error.error_code` from JSON; exit non-zero; no retry. Additional codes: `file_too_large`, `invalid_file`. |
| **4xx** (other) | Log `error.message` and `error.detail` from JSON; exit non-zero; no retry. Note: `error_code` field only exists on 409 and 422 responses, not on 401/403. |
| **5xx** | Exponential backoff retry: 3 attempts at 5 s, 30 s, 120 s. Exit non-zero after all retries exhausted. |
| `curl` exit 28 (timeout) | Same retry logic as 5xx. |
| `curl` exit 35 (TLS handshake) | Same retry logic as 5xx. |
| `curl` exit 56 (network recv) | Same retry logic as 5xx. |

### Idempotent retry (409 same SHA)

PatchCreator may be re-run after a partial failure. If the same version was already
successfully uploaded, the server returns 409 with the existing row's SHA. When the
SHAs match, the build is considered complete — exit 0 so the CI pipeline does not
fail a retry.

---

## Insertion Point

Insert the upload block **after the `# Final Summary` section** (after the last `echo ""` line of the summary) and **before `print_elapsed` and the final `exit`**.

Approximate structure:

```bash
# --- (existing) Final Summary section ---

# Auto-upload to LicenseManager (if configured)
if [[ "$NO_UPLOAD" != "true" ]]; then
    resolve_upload_config   # reads env vars and .patchcreator.local
    if [[ -n "$UPLOAD_URL" && -n "$TOKEN" ]]; then
        upload_patch_to_licensemanager "$TGZ_PATH" "$SHA256" "$UPLOAD_URL" "$TOKEN"
    fi
fi

# --- (existing) print_elapsed and final exit ---
```

All upload logic lives in a `upload_patch_to_licensemanager()` function for testability.

---

## Reproducible Archives

PatchCreator builds archives with deterministic flags (`--sort=name`, `--mtime` anchored to the HEAD commit timestamp, `--owner=0 --group=0 --numeric-owner`). The same source tree at the same HEAD commit produces a byte-identical `.tgz` on any machine. This makes the 409 idempotent-retry path reliable: a CI runner that rebuilds after a transient network failure will send the same SHA and be recognised as a duplicate, not a conflict.

---

## Security

### `.patchcreator.local` gitignore

Add to the project's `.gitignore` (or the `PatchCreator` installer should append it):

```
# PatchCreator local config — contains upload token, never commit
.patchcreator.local
```

### Never write the token to build output

Mask the token in CI:
- GitHub Actions: `echo "::add-mask::$PATCHCREATOR_TOKEN"`
- GitLab CI: use `masked: true` on the CI variable
- Jenkins: use a `Secret text` credential binding

### TLS verification

Never add `-k` or `--insecure`. If the server uses a self-signed certificate,
install it in the system trust store — do not bypass verification.

### File permissions

`.patchcreator.local` holds a long-lived bearer token. Restrict access to the owner only:

```bash
chmod 600 .patchcreator.local
```

PatchCreator does not enforce this — it is the user's responsibility.

---

## Acceptance Test Plan

Run the following checklist manually before shipping the PatchCreator changes:

1. **Success path (201)** — Build a patch with a new version; verify 201 and that the
   patch appears in the LicenseManager admin UI with correct version, sha256, and
   release notes.

2. **Idempotent retry (409, same SHA)** — Re-run the same build. Verify 409 is received
   and PatchCreator exits 0.

3. **SHA mismatch on existing version (409, different SHA)** — Manually corrupt the
   `.tgz`, compute a fake SHA, POST with the real version number.
   Verify 409 is received and PatchCreator exits non-zero with a clear message.

4. **Expired token (401)** — Use a token that has passed its `expires_at`. Verify 401
   generic response, PatchCreator exits non-zero, no retry.

5. **Revoked token (401)** — Revoke a token in the admin UI; attempt upload. Verify
   same generic 401 as expired token (no information leakage between states).

6. **Rate limit (429)** — Temporarily set `api.patch_upload_per_hour = 3` in the DB,
   make 4 rapid uploads. Verify 4th returns 429 with `Retry-After`, PatchCreator
   honors the delay and retries once.

7. **Server error with retry (5xx)** — Mock a 500 response on the first attempt (e.g.
   by temporarily breaking the DB). Verify PatchCreator retries at 5 s and 30 s.

8. **Network timeout** — Set `--max-time 1` and upload a large file. Verify curl
   exit 28 triggers the retry logic.

9. **Missing config (no upload)** — Remove env vars and `.patchcreator.local`.
   Verify the upload step is silently skipped and the build exits 0.

10. **`--no-upload` overrides config** — Set env vars, then run with `--no-upload`.
    Verify no network request is made.

11. **Invalid JSON in config** — Write malformed JSON to `.patchcreator.local`.
    Verify PatchCreator fails immediately with a clear error, **does not** fall back
    to "no upload".

12. **Non-JSON response (Apache 413)** — Exceed `LimitRequestBody` by uploading a
    file larger than the Apache limit. Verify PatchCreator logs the HTTP status code
    and a snippet of the body without crashing on JSON parse.

---

## Related Documentation

- `doc/PATCH_MANAGEMENT.md#api-upload` — server endpoint contract, request format,
  all response codes and `error_code` values
- `doc/API_UPLOAD_TOKENS.md` — token lifecycle, rotation, revocation, incident
  response playbook
