<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Optional interface for host-side translation of TEXT_* keys.
 */

namespace BackupRestore\Contracts;

/**
 * Optional translator adapter for the backup/restore module.
 *
 * When provided, the host application's translation system is used instead
 * of the module's built-in PHP-array locale fallback (locale/{lang}/messages.php).
 *
 * @package BackupRestore
 */
interface TranslatorInterface
{
    /**
     * Translate a TEXT_* key, optionally interpolating named parameters.
     *
     * Placeholders in the translation string use the `{name}` convention
     * (e.g. "Not enough free disk space ({details})."), replaced from the
     * associative $params array by key — not positional %s/%d/vsprintf. This
     * matches the bundled locale/{lang}/messages.php strings (which are
     * extracted from a host using the same `{name}` convention) so a host
     * translator adapter can delegate directly to its own equivalent
     * mechanism. If no translation is found for the given key, the key
     * itself is returned unchanged (with any $params still substituted).
     *
     * @param string $key    Translation key (e.g. TEXT_BACKUP_INSUFFICIENT_DISK_SPACE)
     * @param array<string,string> $params Named placeholder => value map
     * @return string Translated and interpolated string, or the key if not found
     */
    public function translate(string $key, array $params = []): string;
}
