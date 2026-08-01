<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Path-containment guard against zip-slip / path-traversal. Backup archives
 * are treated as untrusted input (SFTP pull, standalone break-glass upload,
 * or an external path passed to restoreFromPath()) — every path built from an
 * archive entry name, a manifest field, or a file-sync relative path must be
 * proven to stay inside its intended destination directory before any
 * filesystem write.
 */

namespace BackupRestore;

/**
 * @package BackupRestore
 */
final class PathGuard
{
    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Assert that $candidate resolves to a path inside (or equal to) $base.
     *
     * Uses purely lexical `.`/`..` segment resolution rather than
     * {@see realpath()} — realpath() requires the target to already exist on
     * disk, which is unusable for a path about to be created (e.g. the
     * destination of a file-sync copy that hasn't been written yet). Both
     * arguments must be absolute (this module targets Linux only, per
     * project convention — no drive-letter/backslash handling).
     *
     * This check alone does not defend against a symlink placed inside the
     * tree that points outside it after extraction — callers that walk an
     * already-extracted tree (e.g. a directory sync) must additionally skip
     * `is_link()` entries that resolve outside $base.
     *
     * @param string $base Absolute directory the candidate must stay under
     * @param string $candidate Absolute path to verify
     * @throws \RuntimeException When $candidate escapes $base, or either
     *         argument is not absolute
     * @return void
     */
    public static function assertContained(string $base, string $candidate): void
    {
        if (!str_starts_with($base, '/') || !str_starts_with($candidate, '/')) {
            throw new \RuntimeException('BackupRestore: PathGuard requires absolute paths');
        }

        $normalizedBase = self::normalize($base);
        $normalizedCandidate = self::normalize($candidate);
        $prefix = rtrim($normalizedBase, '/') . '/';

        if ($normalizedCandidate !== rtrim($normalizedBase, '/') && !str_starts_with($normalizedCandidate . '/', $prefix)) {
            throw new \RuntimeException("BackupRestore: path \"{$candidate}\" escapes its containing directory \"{$base}\"");
        }
    }

    /**
     * Assert that every member of an archive's file list would extract to a
     * location inside $destDir — call this BEFORE running the actual
     * extraction. Checking containment only makes sense before the write: a
     * malicious member has already escaped the destination the instant an
     * extractor writes it, so a post-extraction scan can only ever discover
     * the damage, never prevent it.
     *
     * @param array<int,string> $memberPaths Relative member paths as returned
     *        by a tar/PharData listing (e.g. "./database/x.sql", "files/a.txt")
     * @param string $destDir Absolute destination directory
     * @throws \RuntimeException When any member escapes $destDir
     * @return void
     */
    public static function assertArchiveMembersContained(array $memberPaths, string $destDir): void
    {
        $destDir = rtrim($destDir, '/');
        foreach ($memberPaths as $member) {
            // A leading "./" is the normal tar/PharData relative-path prefix, not
            // an absolute path — strip it before the leading-"/" check below so
            // an ordinary member is never mistaken for an absolute one.
            $relative = rtrim((string) preg_replace('#^\./+#', '', $member), '/');
            if ($relative === '') {
                // "." / "./" — this module's own tarCreateGz() ("tar -czf ... -C
                // dir .") always emits an entry for the root directory itself;
                // it trivially resolves to $destDir, never an escape.
                continue;
            }
            if (str_starts_with($relative, '/')) {
                throw new \RuntimeException("BackupRestore: archive member has an absolute path: \"{$member}\"");
            }

            self::assertContained($destDir, $destDir . '/' . $relative);
        }
    }

    /**
     * Lexically resolve `.`/`..` segments in an absolute path, without
     * touching the filesystem. An absolute path cannot resolve above `/` —
     * a leading `..` is discarded rather than climbing past the root, the
     * same behavior real path resolution has at the filesystem root.
     *
     * @param string $path Absolute path (may not exist on disk)
     * @return string Normalized absolute path, no trailing slash (except root)
     */
    private static function normalize(string $path): string
    {
        $resolved = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }

        return '/' . implode('/', $resolved);
    }
}
