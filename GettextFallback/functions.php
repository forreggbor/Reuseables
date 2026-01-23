<?php

/**
 * GettextFallback - Global Function Wrappers
 *
 * This file provides global gettext-compatible functions that use the
 * GettextFallback class. Include this file early in your application
 * bootstrap to enable transparent gettext compatibility.
 *
 * Usage:
 *   require_once 'GettextFallback/GettextFallback.php';
 *   require_once 'GettextFallback/functions.php';
 *
 * These functions will only be defined if they don't already exist,
 * allowing native gettext functions to take precedence when available.
 *
 * @package GettextFallback
 * @version 1.0.0
 * @license MIT
 */

declare(strict_types=1);

use GettextFallback\GettextFallback;

if (!function_exists('_')) {
    /**
     * Translate a string (gettext shorthand)
     *
     * @param string $msgid Message ID
     * @return string Translated string
     */
    function _(string $msgid): string
    {
        return GettextFallback::translate($msgid);
    }
}

if (!function_exists('gettext')) {
    /**
     * Translate a string
     *
     * @param string $msgid Message ID
     * @return string Translated string
     */
    function gettext(string $msgid): string
    {
        return GettextFallback::translate($msgid);
    }
}

if (!function_exists('ngettext')) {
    /**
     * Translate a plural string
     *
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $n Count for determining plural form
     * @return string Translated string
     */
    function ngettext(string $singular, string $plural, int $n): string
    {
        return GettextFallback::nTranslate($singular, $plural, $n);
    }
}

if (!function_exists('dgettext')) {
    /**
     * Translate a string from a specific domain
     *
     * @param string $domain Text domain
     * @param string $msgid Message ID
     * @return string Translated string
     */
    function dgettext(string $domain, string $msgid): string
    {
        return GettextFallback::dTranslate($domain, $msgid);
    }
}

if (!function_exists('dngettext')) {
    /**
     * Translate a plural string from a specific domain
     *
     * @param string $domain Text domain
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $n Count
     * @return string Translated string
     */
    function dngettext(string $domain, string $singular, string $plural, int $n): string
    {
        return GettextFallback::dnTranslate($domain, $singular, $plural, $n);
    }
}

if (!function_exists('pgettext')) {
    /**
     * Translate a string with context
     *
     * @param string $context Context for disambiguation
     * @param string $msgid Message ID
     * @return string Translated string
     */
    function pgettext(string $context, string $msgid): string
    {
        return GettextFallback::pTranslate($context, $msgid);
    }
}

if (!function_exists('npgettext')) {
    /**
     * Translate a plural string with context
     *
     * @param string $context Context for disambiguation
     * @param string $singular Singular form
     * @param string $plural Plural form
     * @param int $n Count
     * @return string Translated string
     */
    function npgettext(string $context, string $singular, string $plural, int $n): string
    {
        return GettextFallback::npTranslate($context, $singular, $plural, $n);
    }
}

if (!function_exists('textdomain')) {
    /**
     * Set the current text domain
     *
     * @param string|null $domain Text domain name (null to get current)
     * @return string Current domain name
     */
    function textdomain(?string $domain = null): string
    {
        if ($domain !== null) {
            GettextFallback::setDomain($domain);
        }
        return GettextFallback::getDomain();
    }
}

if (!function_exists('bindtextdomain')) {
    /**
     * Bind a text domain to a directory
     *
     * @param string $domain Text domain name
     * @param string $path Directory path
     * @return string The bound path
     */
    function bindtextdomain(string $domain, string $path): string
    {
        GettextFallback::bindDomain($domain, $path);
        return $path;
    }
}

if (!function_exists('bind_textdomain_codeset')) {
    /**
     * Set the encoding for a text domain
     *
     * Note: GettextFallback always uses UTF-8, this function is provided
     * for compatibility but the codeset parameter is ignored.
     *
     * @param string $domain Text domain name
     * @param string $codeset Character encoding (ignored, always UTF-8)
     * @return string The codeset (always 'UTF-8')
     */
    function bind_textdomain_codeset(string $domain, string $codeset): string
    {
        // GettextFallback always uses UTF-8
        return 'UTF-8';
    }
}
