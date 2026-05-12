# Path Safety in PatchModule

PatchModule copies, backs up, and removes files based on paths that ultimately originate from the
patch manifest embedded in the downloaded archive. Although the archive is SHA-256 verified and
signed, defence-in-depth requires that every file-path operation is validated independently before
touching the filesystem.

## The `safeJoin()` Helper

`PatchFileManager` exposes a private method:

```php
private function safeJoin(string $base, string $relativePath): string
```

It validates that `$relativePath` is safe to join with `$base` and returns the final absolute path.
It **throws `\InvalidArgumentException`** when any of the following conditions are true:

- The path is an empty string.
- The path begins with `/` (Unix absolute path).
- The path matches `^[a-zA-Z]:` (Windows drive letter).
- The path contains `\` (backslash) or a NUL byte.
- Any path segment, after splitting on `/`, equals `..`, `.`, or an empty string (collapsed `//`).
- The `realpath()` of the base directory, when appended with the validated relative path, does not
  start with `realpath($base) . '/'` — i.e. the result would escape the base directory.

The base directory must exist. Because destination files may not exist yet (new files being installed),
only the base is passed to `realpath()`, not the full destination. The segment-level checks are
sufficient to prevent traversal without requiring the leaf to exist.

### Where `safeJoin()` Is Applied

| Call site                       | Base            | Path source                |
|---------------------------------|-----------------|----------------------------|
| `copyFiles()` — destination     | `$rootPath`     | `manifest.files`           |
| `copyFiles()` — source          | extract `files/` dir | `manifest.files`      |
| `backupAffectedFiles()`         | `$rootPath`     | `manifest.files` + `manifest.removed_files` |
| `removeFiles()`                 | `$rootPath`     | `manifest.removed_files`   |
| `rollbackFiles()` — restore     | `$rootPath`     | `snapshot_meta.json` lists |
| `rollbackFiles()` — snapshot    | `<snapshot>/files` | `snapshot_meta.json` lists |

Any traversal attempt aborts the entire operation immediately. No partial writes occur.

### Error Code

When `safeJoin()` throws, the caller returns:

```php
['success' => false, 'error_code' => 'invalid_manifest_path', ...]
```

`PatchInstaller` propagates this through `handleInstallFailure()`, which records
`[invalid_manifest_path] <message>` in the `patch_history.error_message` column and triggers rollback.

## `migrations/` Directory Guard (v1.8.0+)

After extraction, every entry under `<extractDir>/migrations/` is validated before execution:

- Must be a regular file (`is_file()` true, `is_link()` false).
- `realpath()` of the file must still be a child of `realpath($extractDir . '/migrations/')`.

If any entry fails, the install aborts with `error_code = 'invalid_archive'`. This prevents path
traversal via archive members such as `migrations/../../../etc/passwd` and ensures that symlinks
inside the migrations directory cannot redirect execution to arbitrary host files.

`safeJoin()` is **not** used here because migration files are executed in place from the extracted
directory, not copied to the project root. The `realpath()` confinement check serves the same
purpose for the execution path.

## Symlink Rejection

### In Archive Extraction

After `extractPatch()` completes, the extracted tree is scanned for symlinks. If any symlink is found:

- The extraction directory is cleaned up.
- The install fails with `error_code = 'invalid_archive'`.

This check covers both the `files/` and `migrations/` subtrees. The migrations guard (above) adds a
second, independent realpath confinement check specifically for migration files before they are
executed.

### In `scanDirectory()`

The `scanDirectory()` method (used to verify installed files post-install) explicitly skips symlinks:

```php
if ($file->isLink()) {
    continue;
}
```

This means symlinks in the project root are silently ignored during the verification scan. They are
never read through or followed.

## `ExecTarAdapter` Hardening

The `tar` invocation uses `--no-same-owner --no-same-permissions` to prevent the archive from forcing
unexpected file ownership or permissions onto the destination:

```
tar --no-same-owner --no-same-permissions -xzf <archive> -C <dest>
```

Path-traversal in `..`-named tar members is caught by the post-extraction realpath walk described
above, making the defence portable across different `tar` implementations.

## Atomic File Writes

`PatchFileManager::copyFiles()` and `atomicCopy()` (used during rollback) write files atomically:

1. The source is copied to a `.patchtmp` sibling (`destPath . '.patchtmp'`).
2. `rename()` moves the temp file into place — POSIX guarantees this is atomic on the same filesystem.
3. If `rename()` fails (cross-filesystem `EXDEV`), the module falls back to a non-atomic `copy()` +
   `unlink()` of the temp file. A WARNING is logged when the fallback is used.
4. The temp file is removed on any failure before returning an error.

The effect: a PHP process killed mid-install cannot leave a half-written file visible to web requests.
Any surviving `.patchtmp` files are swept at the start of the next install (files older than 24 hours
are deleted by `sweepStaleTmpFiles()`).

## File Mode Preservation

When `backupAffectedFiles()` creates a snapshot, it records the original `chmod` value of every file
being replaced or removed in `snapshot_meta.json` under the `modes` key:

```json
{
  "modes": {
    "bin/deploy.sh": 493,
    "config/app.php": 420
  }
}
```

(Values are the integer result of `fileperms() & 0777`.)

On rollback, `rollbackFiles()` reads `modes` and calls `chmod()` after each file is restored, so
executable scripts and configuration files keep their original permissions. New files (not previously
present) receive the default mode `0644`.

## `PharTarAdapter`

`PharTarAdapter` (the fallback when shell extraction is unavailable) relies on PHP's `PharData` class,
which also strips leading `/` and resolves member names. The post-extraction symlink walk applies
equally to both adapters.
