<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * SQL identifier escaping/validation shared by RestoreEngine and BackupRestore.
 * Backup archives restored through this module are treated as untrusted input
 * (they may arrive via SFTP pull, the standalone break-glass upload page, or
 * an external path passed to restoreFromPath()) — table/trigger/foreign-key
 * names read back from a restored database via `information_schema`/`SHOW
 * CREATE TRIGGER` must never be interpolated into DDL without escaping.
 */

namespace BackupRestore;

/**
 * @package BackupRestore
 */
final class Identifier
{
    private function __construct()
    {
        // Static utility class — not instantiable.
    }

    /**
     * Backtick-quote a MySQL/MariaDB identifier (database, table, column,
     * trigger, or constraint name), doubling any embedded backtick per the
     * standard MySQL identifier-quoting rule. Safe for any string input —
     * unlike {@see assertValid()}, this never rejects, it only escapes.
     *
     * @param string $name Raw identifier (untrusted)
     * @return string Backtick-quoted identifier, e.g. "`a``b`" for `a`b`
     */
    public static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Whitelist-validate an identifier that will be used to build another
     * identifier (e.g. the `_restore_<ts>`/`_old_<ts>` temp database names
     * derived from the host-supplied `db_credentials['database']`). Rejects
     * anything outside [A-Za-z0-9_] or longer than MySQL's 64-character limit.
     *
     * @param string $name
     * @throws \InvalidArgumentException When $name fails the whitelist
     * @return string $name, unchanged, once validated
     */
    public static function assertValid(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("BackupRestore: invalid SQL identifier \"{$name}\"");
        }

        return $name;
    }

    /**
     * Build a comma-separated, single-quoted SQL string-literal list for use
     * inside an `IN (...)` clause — e.g. table names being compared against
     * `information_schema.TABLE_NAME` (a string column, not an identifier
     * position, so {@see quote()}'s backtick-doubling does not apply here;
     * this escapes backslash and single-quote per standard MySQL string
     * literal rules instead).
     *
     * @param array<int,string> $values Raw values (untrusted)
     * @return string e.g. "'a','b\\'s'" for ['a', "b's"]
     */
    public static function quoteStringList(array $values): string
    {
        return implode(',', array_map(
            static fn (string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'",
            $values
        ));
    }
}
