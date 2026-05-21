# CronAdmin — Locking Design

## Per-job flock

Each job uses a POSIX `flock(LOCK_EX|LOCK_NB)` on `<lock_dir>/<job_key>.lock`.

- **Non-blocking:** if the lock is already held, the dispatcher skips the job with `last_status='skipped'`, `last_error='locked'` — never waits.
- **PID recording:** the locking process writes its PID to the lock file on acquire. The next tick reads it for stale-lock detection.
- **Release:** `flock(LOCK_UN)` + `fclose()` always happens in a `finally` block — released even if the task throws.

## Stale-lock detection

Whenever a lock file exists, the dispatcher attempts PID-liveness detection before waiting for an mtime timeout:

1. Read the PID from the lock file.
2. Call `posix_kill($pid, 0)` to probe liveness (POSIX-only — Linux/macOS).
   - Returns `false` → process dead → log INFO, unlink lock file, proceed to acquire immediately (no mtime wait required).
   - Returns `true` → process alive → check mtime:
     - `(time() - mtime) > lock_timeout_seconds` → log WARNING and skip (alive process exceeded timeout — indicates a hung task).
     - Otherwise → skip silently (normal in-progress job).
3. If the PID line is unreadable or `posix_kill` is unavailable, fall back to the mtime threshold only.

**EPERM edge case:** `posix_kill($pid, 0)` returns `true` both when the PID is alive AND when the caller lacks permission to signal it (EPERM). The module treats EPERM as "alive" (conservative). Mtime-based timeout is the fallback reclaim mechanism in that case.

**Non-POSIX hosts (Windows):** `posix_kill()` is absent. The PID probe is skipped entirely. Stale-lock reclaim relies solely on the mtime threshold (`lock_timeout_seconds`).

**OOM / SIGKILL recovery:** Before v1.1.0 the PID probe only ran after the mtime threshold, meaning a process killed by the OOM killer held the lock for up to `lock_timeout_seconds`. The probe now runs on every lock-file existence check, so dead-PID locks are reclaimed on the next dispatch tick regardless of mtime.

## DST fall-back guard

For `daily`, `weekly`, and `monthly` jobs: if `last_status='success'` and `DATE(last_run_at)` equals today's date, `Scheduler::shouldRun()` returns `false`. This prevents the job from firing twice during a DST fall-back hour (e.g. 02:00 → 01:59 → 02:00 again on EU autumn change).

This guard does NOT apply to `every_n_minutes` or `hourly` — those are minute-level semantics, not date-level.

## DST spring-forward

A job scheduled for `default_hour=2` on the EU spring-change day simply does not fire — the hour 02:xx does not exist in `Europe/Budapest` on that date. No catch-up mechanism is implemented. The job runs normally the next day. This matches standard POSIX cron behaviour.

**Documentation note:** the Scheduler test suite must include a sub-case that explicitly asserts the skipped occurrence and marks it as "skipped by design."

## Sync flock

`ManifestSyncService::sync()` is called from `Dispatcher::dispatch()` when the manifest file's mtime changes. To prevent two overlapping ticks from both detecting the change and both writing a `sync_cron_manifest` audit entry, the dispatcher acquires a non-blocking flock on `<lock_dir>/.sync.lock` before calling `sync()`. If the lock is already held, the current tick skips the sync call (it will re-attempt next minute after the mtime file is written by the winning tick).

Sync from `AdminActions::index()` (HTTP-triggered) does NOT acquire the sync flock — it runs unconditionally on every page load by design (the admin page is the source of truth).

## Crontab cadence requirement

The Scheduler assumes `* * * * *` (every minute). A 5-minute `*/5` cadence causes between-tick triggers to be silently missed — e.g. a job scheduled at `every_n_minutes=3` would only fire when the crontab tick aligns with `minuteOfDay % 3 === 0`. This is a deployment requirement documented loudly in `README.md`.
