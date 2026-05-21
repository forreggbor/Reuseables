# CronAdmin — Security Design

## Auth gate placement

The module **never** authorises requests itself. The host router must apply existing admin authentication middleware to all `/admin/cron*` routes **before** calling AdminActions methods. AdminActions then calls `auth->isAuthorized($action)` as a defence-in-depth check before every mutation.

Route protection is the host's responsibility; module protection is defence-in-depth.

## HTTP method gate

Every AdminActions method checks `$_SERVER['REQUEST_METHOD']` at the top and responds with `HTTP 405 Method Not Allowed` on mismatch. This runs before any CSRF or auth check — cheapest rejection first.

| Method | Endpoint |
|--------|----------|
| GET    | `index`, `pollRunStatus` |
| POST   | `saveOne`, `toggle`, `runNow`, `toggleDispatcher` |

## CSRF validation

All mutating POST endpoints call `csrf->validate()` (zero-arg — adapter reads `$_POST['csrf_token']` internally). On failure: `HTTP 419` + JSON error. The field name `csrf_token` is hard-coded; all JS AJAX calls post under this name.

## Broken-manifest endpoint guard

All AJAX mutation endpoints re-run `ManifestReader::load()` defensively before acting. On `InvalidManifestException`: `HTTP 422` + JSON `{error, details}`. Prevents crafted requests from bypassing the UI's disabled buttons while the manifest is broken.

## Run-Now atomic claim

`runNow()` uses `UPDATE … WHERE trigger_pending=0` + `rowCount()===1` to atomically claim the job. If the atomic UPDATE matches 0 rows (already claimed), returns `HTTP 409`. This prevents stacking multiple simultaneous runs of the same job.

## CSP safety

No inline `<style>` or `<script>` in module views. I18n strings and runtime flags used by JS are exposed via `data-cra-*` attributes on `.cra-root`. The JS reads them at boot — compatible with strict `script-src` policies that disallow `unsafe-inline`.

## base_url validation

`ConfigValidator` validates `base_url`:
- Must start with `/` (same-origin path — no scheme, no host).
- No `..` (directory traversal).
- No `//` (double-slash confusion).
- No `%` (percent-encoding confusion).
- No whitespace.

## Dispatcher kill switch

The kill switch is **opt-out** — it defaults to enabled (`get()` returns `true` when not yet set). Disabling requires an explicit admin action. The dispatcher reads it on every tick; on `false` it logs INFO and skips all job execution (both scheduled and Run-Now), but still runs manifest sync so the admin UI stays current.

## Output capture scope

`ob_start()` / `ob_get_clean()` captures only the task's `run()` method output. The task class, the class's constructor, and any framework bootstrap code before `ob_start()` are not captured. This is intentional — captured output is stored as `last_output_excerpt` for debugging, not for security-sensitive data.

## Load-bearing trust assumption: manifest_path

`ManifestReader::load()` executes `require $manifestPath` on every dispatch tick and every admin page load. This is intentional — the manifest is a PHP file that returns a configuration array.

**This means `manifest_path` is a critical security boundary:**

- The file MUST reside in a directory that is owned by the deploy user and **not writable by the web process**.
- The directory MUST be **outside the document root** (web-unreachable).
- The path MUST NOT be derived from any user input, environment variable, or runtime configuration — it must be a hardcoded constant in the host bootstrap.

`ConfigValidator` rejects paths containing `..` but does not enforce a directory allowlist. The filesystem ACL is the primary protection. If an attacker can write to the manifest file (via a file upload vulnerability, container misconfiguration, or deploy pipeline compromise), they have unconditional RCE on every dispatch tick.

Recommended directory: `<project_root>/cron/jobs.php` where `cron/` is outside `public/` and owned 640 by the deploy user.
