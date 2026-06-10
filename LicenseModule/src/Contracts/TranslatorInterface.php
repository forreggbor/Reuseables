<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Optional translator contract for the LicenseModule admin interface.
 */

declare(strict_types=1);

namespace LicenseModule\Contracts;

/**
 * TranslatorInterface - optional host-side translator bridge
 *
 * When provided to the LicenseModule admin page, all visible strings are resolved
 * via this interface instead of the module's built-in PHP-array locale files.
 * If null, the module falls back to its own locale/{locale}/messages.php.
 *
 * @package LicenseModule
 */
interface TranslatorInterface
{
    /**
     * Translate a TEXT_* key with optional positional parameters.
     *
     * @param string       $key    Translation key (e.g. TEXT_LABEL_STATUS)
     * @param array<mixed> $params Positional values substituted into %s/%d placeholders
     * @return string Translated string, or $key if not found
     */
    public function t(string $key, array $params = []): string;
}
