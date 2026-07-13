<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Renders the license admin page: locale/translator resolution and the
 * output-buffered view include. Owns no license/gating semantics.
 */

declare(strict_types=1);

namespace LicenseModule;

use LicenseModule\Contracts\TranslatorInterface;

/**
 * Admin page renderer
 *
 * A narrow, mostly-stateless renderer: it owns locale/translator resolution,
 * the `$t` translation closure, and the actual view inclusion — nothing else.
 * It has no dependency on FeatureGate/LicenseValidator/DatabaseAdapterInterface;
 * LicenseModule gathers all license/gating data and hands it here as a plain
 * array. New admin-page presentation belongs in the view file itself (which
 * already owns all per-section branching); this class cannot accumulate
 * per-section logic because it never sees individual sections, only a bag of
 * already-prepared data.
 */
class AdminPageRenderer
{
    private string $viewPath;
    private string $localeDir;
    private string $defaultLocale;
    private ?TranslatorInterface $defaultTranslator;

    /** @var array<string, array> Per-locale loaded messages, keyed by locale code */
    private array $localeCache = [];

    /** @var array|null en_US fallback messages, loaded once and reused */
    private ?array $fallbackCache = null;

    /**
     * @param string $viewPath Absolute path to the admin page view file
     * @param string $localeDir Absolute path to the module's locale/ directory
     * @param string $defaultLocale Fallback locale code when no per-call override is given
     * @param TranslatorInterface|null $defaultTranslator Host translator injected at LicenseModule construction time
     */
    public function __construct(
        string $viewPath,
        string $localeDir,
        string $defaultLocale,
        ?TranslatorInterface $defaultTranslator
    ) {
        $this->viewPath = $viewPath;
        $this->localeDir = $localeDir;
        // $defaultLocale is host-supplied config (LicenseModule's 'locale' option) —
        // validate it here too, so an invalid value can never become an unsafe
        // fallback target in translateBuiltin()'s own validation.
        $this->defaultLocale = preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]{2,4})?$/', $defaultLocale)
            ? $defaultLocale
            : 'en_US';
        $this->defaultTranslator = $defaultTranslator;
    }

    /**
     * Whether the view file exists on disk. Callers should check this before
     * gathering any view data, to avoid wasted work when the view is absent.
     *
     * @return bool
     */
    public function viewExists(): bool
    {
        return file_exists($this->viewPath);
    }

    /**
     * Render the admin page view with the given data and options.
     *
     * @param array $viewData Prepared license/gating data (license, status, tier,
     *                        addons, featureKeys, isLegacy, history, daysRemaining,
     *                        graceDaysRemaining — see views/admin/license.php)
     * @param array $options Full options array as passed to LicenseModule::renderAdminPage()
     * @return string Rendered HTML fragment, or empty string if the view file is absent
     */
    public function render(array $viewData, array $options): string
    {
        if (!file_exists($this->viewPath)) {
            return '';
        }

        // NOTE (deliberate — do not "clean up"): $callLocale is always computed
        // here, even though it's only consulted in the built-in-lookup branch
        // below. This mirrors the ORIGINAL behavior exactly: when any translator
        // is active (per-call or constructor-injected), $options['locale'] is
        // silently ignored, because TranslatorInterface::t(string $key, array
        // $params = []): string has no locale parameter. Do not move this
        // computation into the else-branch (cosmetic no-op that invites
        // confusion) and never pass $callLocale into $callTranslator->t() — the
        // interface does not accept it and doing so would be a TypeError, not a
        // feature.
        $callLocale = $options['locale'] ?? $this->defaultLocale;

        // Preserves the exact original priority: per-call translator wins over
        // the constructor-injected default translator; only when NEITHER is set
        // does the built-in locale-array lookup run (using $callLocale).
        $callTranslator = ($options['translator'] ?? null) instanceof TranslatorInterface
            ? $options['translator']
            : $this->defaultTranslator;

        if ($callTranslator !== null) {
            $t = static fn(string $key, mixed ...$params): string => $callTranslator->t($key, $params);
        } else {
            $t = fn(string $key, mixed ...$params): string => $this->translateBuiltin($callLocale, $key, $params);
        }

        return $this->renderViewFile($viewData, $options, $t);
    }

    /**
     * Output-buffered inclusion of the view file. The view reads $viewData's
     * keys as plain local variables, plus $options and $t (already local to
     * this method as its own parameters).
     *
     * @param array $viewData
     * @param array $options
     * @param callable $t
     * @return string
     * @throws \Throwable Re-thrown after cleaning the output buffer, so a
     *                     rendering failure propagates to the caller unchanged
     *                     (e.g. UniCMS wraps LicenseModule::renderAdminPage()
     *                     in its own try/catch and depends on this).
     */
    private function renderViewFile(array $viewData, array $options, callable $t): string
    {
        extract($viewData, EXTR_SKIP);
        ob_start();
        try {
            include $this->viewPath;
            return ob_get_clean() ?: '';
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Built-in locale-array translation lookup (used when no translator — per-call
     * or constructor-injected — is active). Falls back to en_US, then to the raw key.
     *
     * @param string $locale
     * @param string $key
     * @param array $params
     * @return string
     */
    private function translateBuiltin(string $locale, string $key, array $params): string
    {
        // $locale can originate from a host-supplied 'locale' option (or the
        // constructor-level default, itself host-supplied config) and must never
        // be trusted for filesystem path construction unsanitized. Whitelist the
        // expected locale-code shape (e.g. en_US, hu_HU) — anything else falls
        // back to the default locale, which prevents path traversal outright
        // since the allowed character set cannot contain '/' or '.'.
        if (!preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]{2,4})?$/', $locale)) {
            $locale = $this->defaultLocale;
        }

        if (!isset($this->localeCache[$locale])) {
            $path = $this->localeDir . '/' . $locale . '/messages.php';
            if (file_exists($path)) {
                $loaded = require $path;
                $this->localeCache[$locale] = is_array($loaded) ? $loaded : [];
            } else {
                $this->localeCache[$locale] = [];
            }
        }

        if ($this->fallbackCache === null) {
            $path = $this->localeDir . '/en_US/messages.php';
            if (file_exists($path)) {
                $loaded = require $path;
                $this->fallbackCache = is_array($loaded) ? $loaded : [];
            } else {
                $this->fallbackCache = [];
            }
        }

        $string = $this->localeCache[$locale][$key] ?? $this->fallbackCache[$key] ?? $key;

        if ($params !== [] && (str_contains($string, '%s') || str_contains($string, '%d'))) {
            return vsprintf($string, $params);
        }

        return $string;
    }
}
