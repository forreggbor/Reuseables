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

## Symlink Rejection

### In Archive Extraction

After `extractPatch()` completes, the extracted tree is scanned for symlinks. If any symlink is found:

- The extraction directory is cleaned up.
- The install fails with `error_code = 'invalid_archive'`.

This prevents an archive member like `files/etc -> /etc` from causing `copy()` to read sensitive host
files and write their contents into the project tree.

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

## `PharTarAdapter`

`PharTarAdapter` (the fallback when shell extraction is unavailable) relies on PHP's `PharData` class,
which also strips leading `/` and resolves member names. The post-extraction symlink walk applies
equally to both adapters.
