<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Evaluates tier/addon/feature gating purely from server-provided license data.
 */

declare(strict_types=1);

namespace LicenseModule;

/**
 * Feature gating based on license tier, addons, and features
 *
 * LicenseModule is the single source of truth for tier/addon/feature data: it
 * receives the license server's fully-resolved feature set and evaluates gating
 * requests directly against it. There is no local tier→module or addon→module
 * mapping here — hosts state *what* a feature requires (an addon, a tier, a
 * minimum tier level, or a feature key) and this class answers true/false.
 *
 * A license with no tier data at all (the legacy `['all']` sentinel, or no
 * license row) is treated as unknown and denies everything by default —
 * "unrestricted" access must be expressed server-side as an explicit tier/
 * feature/addon set, never as absence of data.
 */
class FeatureGate
{
    /** @var array|null Cached, normalized license data ({tier, addons, feature_keys, package}) or null for a legacy license */
    private ?array $licenseCache = null;

    /** @var bool Whether the license data has been fetched from the provider yet (distinguishes "not yet fetched" from a legitimately null/legacy result) */
    private bool $licenseFetched = false;

    /** @var callable License data provider */
    private $licenseProvider;

    /**
     * @param callable $licenseProvider Callable that returns the parsed license
     *                                  features array ({tier, addons, feature_keys, package})
     *                                  or null for a legacy/unrestricted-format license
     */
    public function __construct(callable $licenseProvider)
    {
        $this->licenseProvider = $licenseProvider;
    }

    /**
     * Get full addon rows from the license data
     *
     * Returns each addon as an associative array with feature_key, name, slug, and description.
     * Returns an empty array for a legacy license.
     *
     * @return array<int, array{feature_key: string, name: string, slug: string, description: string|null}>
     */
    public function getAddons(): array
    {
        $license = $this->getLicenseData();

        if ($license === null) {
            return [];
        }

        return $license['addons'] ?? [];
    }

    /**
     * Get the full addon catalog for the license's package — every addon
     * available in that package, not just the ones currently activated.
     * Returns an empty array for a legacy license or when the server sent
     * no catalog data.
     *
     * @return array<int, array{feature_key: string, name: string, description: string|null,
     *                          price: mixed, price_currency: string|null, billing_period: string|null,
     *                          requires_tier_level: int|null, status: string|null, sort_order: mixed,
     *                          activated: bool, tier_eligible: bool}>
     */
    public function getAddonCatalog(): array
    {
        $license = $this->getLicenseData();

        if ($license === null) {
            return [];
        }

        return $license['addon_catalog'] ?? [];
    }

    /**
     * Get the flat list of enabled feature keys resolved by the license server
     *
     * This is the authoritative enabled-feature set: the server has already merged
     * the tier's own feature keys (if any) with the enabled addons' feature keys.
     * Returns an empty array for a legacy license or when the server sent no features.
     *
     * @return string[]
     */
    public function getFeatureKeys(): array
    {
        $license = $this->getLicenseData();

        if ($license === null) {
            return [];
        }

        return $license['feature_keys'] ?? [];
    }

    /**
     * Get the license's package information, if any
     *
     * @return array{id: int, name: string|null, slug: string|null}|null
     */
    public function getPackage(): ?array
    {
        $license = $this->getLicenseData();

        if ($license === null) {
            return null;
        }

        return $license['package'] ?? null;
    }

    /**
     * Get current tier information
     *
     * @return array|null Tier object {slug, name, level, description}; null for a
     *                     legacy license, or for a valid license with no tier assigned
     *                     (addon-only mode)
     */
    public function getTier(): ?array
    {
        $license = $this->getLicenseData();

        if ($license === null) {
            return null;
        }

        $tierData = $license['tier'] ?? null;

        if (is_array($tierData)) {
            return [
                'slug'        => $tierData['slug'] ?? null,
                'name'        => $tierData['name'] ?? null,
                'level'       => (int) ($tierData['level'] ?? 0),
                'description' => $tierData['description'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Get current tier level
     *
     * @return int Tier level (0 if legacy/invalid or no tier assigned)
     */
    public function getTierLevel(): int
    {
        $tier = $this->getTier();

        return $tier['level'] ?? 0;
    }

    /**
     * Check whether the current tier matches an exact slug
     *
     * @param string $slug Tier slug to match
     * @return bool
     */
    public function hasTier(string $slug): bool
    {
        $tier = $this->getTier();

        return $tier !== null && ($tier['slug'] ?? null) === $slug;
    }

    /**
     * Check whether the current tier level meets a minimum threshold
     *
     * This is a pure predicate — it does not enforce or block anything. The
     * host is responsible for acting on the boolean result (e.g. redirecting,
     * rendering an upsell, or blocking a route) when this returns false.
     *
     * Explicitly denies on a legacy license regardless of $minLevel: a bare
     * numeric comparison against getTierLevel() would otherwise return true
     * for $minLevel <= 0, since a legacy/no-tier license's level is 0 — that
     * would violate deny-by-default for a trivial/degenerate requirement.
     *
     * @param int $minLevel Minimum required tier level
     * @return bool True if the current tier level is >= $minLevel
     */
    public function requireTierLevel(int $minLevel): bool
    {
        if ($this->getLicenseData() === null) {
            return false;
        }

        return $this->getTierLevel() >= $minLevel;
    }

    /**
     * Check if an addon is enabled
     *
     * Checks the license's addon list (feature_key match). Use this to gate
     * on a specific purchasable/marketed add-on. For a general "is this feature
     * available" check that also covers tier-granted features with no matching
     * addon entry, use {@see hasFeature()} instead.
     *
     * @param string $addonKey Addon feature key
     * @return bool
     */
    public function hasAddon(string $addonKey): bool
    {
        $license = $this->getLicenseData();

        // Legacy license - deny by default (unrestricted access must be explicit server-side)
        if ($license === null) {
            return false;
        }

        $enabledAddons = $license['addons'] ?? [];

        foreach ($enabledAddons as $addon) {
            if (($addon['feature_key'] ?? null) === $addonKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get enabled addon feature keys
     *
     * @return string[]
     */
    public function getEnabledAddons(): array
    {
        $license = $this->getLicenseData();

        // Legacy license - deny by default
        if ($license === null) {
            return [];
        }

        $enabledAddons = $license['addons'] ?? [];

        return array_values(array_filter(array_map(
            fn($addon) => $addon['feature_key'] ?? null,
            $enabledAddons
        )));
    }

    /**
     * Check whether a specific feature key is enabled
     *
     * The general-purpose gating check: evaluates against the server's flat
     * resolved feature set, which covers both tier-granted and addon-granted
     * feature keys regardless of whether a matching addon object exists.
     *
     * @param string $key Feature key
     * @return bool
     */
    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->getFeatureKeys(), true);
    }

    /**
     * Evaluate a single gating requirement against the current license
     *
     * Deny-by-default dispatch: exactly one recognized top-level key is
     * expected. An empty requirement, an unrecognized key, or more than one
     * top-level key always evaluates to false.
     *
     * Recognized keys:
     *   - 'addon'          => string          — {@see hasAddon()}
     *   - 'tier'           => string          — {@see hasTier()}
     *   - 'min_tier_level' => int|numeric     — {@see requireTierLevel()}; a whole-number
     *                         string (e.g. '3') or integer-valued float (e.g. 3.0) is
     *                         accepted alongside a strict int, since config-sourced values
     *                         (JSON/YAML/env) commonly arrive as numeric strings — anything
     *                         else (non-numeric, fractional) still denies
     *   - 'feature'        => string          — {@see hasFeature()}
     *   - 'any_of'         => array of requirements, true if any evaluates true
     *   - 'all_of'         => array of requirements, true only if all evaluate
     *                         true (an empty list is deny-by-default, never
     *                         vacuously true)
     *
     * @param array $requirement Single-key requirement, or an 'any_of'/'all_of' composition
     * @return bool
     */
    public function allows(array $requirement): bool
    {
        if (count($requirement) !== 1) {
            return false;
        }

        $key = array_key_first($requirement);
        $value = $requirement[$key];

        return match ($key) {
            'addon' => $this->resolveAddon($value),
            'tier' => $this->resolveTier($value),
            'min_tier_level' => $this->resolveMinTierLevel($value),
            'feature' => $this->resolveFeature($value),
            'any_of' => $this->resolveAnyOf($value),
            'all_of' => $this->resolveAllOf($value),
            default => false,
        };
    }

    /**
     * @see allows() — resolves a single 'addon' requirement
     */
    private function resolveAddon(mixed $value): bool
    {
        return is_string($value) && $this->hasAddon($value);
    }

    /**
     * @see allows() — resolves a single 'tier' requirement
     */
    private function resolveTier(mixed $value): bool
    {
        return is_string($value) && $this->hasTier($value);
    }

    /**
     * @see allows() — resolves a single 'feature' requirement
     */
    private function resolveFeature(mixed $value): bool
    {
        return is_string($value) && $this->hasFeature($value);
    }

    /**
     * @see allows() — resolves an 'any_of' composition
     */
    private function resolveAnyOf(mixed $value): bool
    {
        return is_array($value) && $this->allowsAnyOf($value);
    }

    /**
     * @see allows() — resolves an 'all_of' composition
     */
    private function resolveAllOf(mixed $value): bool
    {
        return is_array($value) && $this->allowsAllOf($value);
    }

    /**
     * @see allows() — resolves a 'min_tier_level' requirement, coercing common
     * config-sourced numeric representations via {@see toWholeInt()}
     */
    private function resolveMinTierLevel(mixed $value): bool
    {
        $level = self::toWholeInt($value);

        return $level !== null && $this->requireTierLevel($level);
    }

    /**
     * Coerce a value into a whole-number int if it unambiguously represents one
     * (a strict int, an integer-valued float, or a numeric string with no
     * fractional part) — otherwise null. Used by allows()'s 'min_tier_level'
     * to accept common config-sourced representations without silently
     * denying on a type mismatch that isn't actually ambiguous data.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function toWholeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Whether the current license has no tier/feature data at all — the legacy
     * `['all']` sentinel, no license row, or a provider failure (see
     * {@see getLicenseData()}). Reads through the same memoized fetch as every
     * other gating primitive, so calling this after any other gating call in
     * the same request is free (no extra provider/database call).
     *
     * @return bool
     */
    public function isLegacy(): bool
    {
        return $this->getLicenseData() === null;
    }

    /**
     * Clear the memoized license data, forcing the next gating call to re-fetch
     * from the provider. Call after a fresh validate() so gating reflects the
     * newly-stored license row within the same request.
     */
    public function clearCache(): void
    {
        $this->licenseCache = null;
        $this->licenseFetched = false;
    }

    /**
     * Get license data from the provider, memoized for the lifetime of this
     * instance (i.e. for the duration of the request).
     *
     * Returns null for a true legacy/unrestricted license (no tier data at all
     * — the historical `['all']` sentinel, or no license row). A license with a
     * real tier object but no feature keys of its own (tier-less "addon-only"
     * mode) is NOT legacy — it is represented here as a normal array with
     * `tier` possibly null, evaluated truthfully against whatever addons/
     * features were actually granted.
     *
     * If the provider throws (e.g. a transient database failure), this is
     * treated the same as a legacy license — deny by default — rather than
     * letting the exception propagate through every gating call in the
     * request. The provider is responsible for logging the underlying cause
     * (see LicenseModule::getParsedFeatures()); this is a last-resort safety
     * net for provider bugs or failures the provider itself didn't catch.
     * The fetch is attempted only once per request even on failure, so a
     * persistent outage doesn't retry the failing call on every gating check.
     *
     * @return array{tier: array|null, addons: array, feature_keys: array, package: array|null, addon_catalog: array}|null
     */
    private function getLicenseData(): ?array
    {
        if (!$this->licenseFetched) {
            try {
                $features = ($this->licenseProvider)();
            } catch (\Throwable) {
                $features = null;
            }

            $this->licenseCache = $features === null ? null : [
                'tier'          => $features['tier'] ?? null,
                'addons'        => $features['addons'] ?? [],
                'feature_keys'  => $features['feature_keys'] ?? [],
                'package'       => $features['package'] ?? null,
                'addon_catalog' => $features['addon_catalog'] ?? [],
            ];
            $this->licenseFetched = true;
        }

        return $this->licenseCache;
    }

    /**
     * Evaluate an 'any_of' composition — true if any sub-requirement matches
     *
     * @param array $requirements List of requirement arrays
     * @return bool
     */
    private function allowsAnyOf(array $requirements): bool
    {
        foreach ($requirements as $req) {
            if (is_array($req) && $this->allows($req)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate an 'all_of' composition — true only if every sub-requirement
     * matches. An empty list is deny-by-default, never vacuously true.
     *
     * @param array $requirements List of requirement arrays
     * @return bool
     */
    private function allowsAllOf(array $requirements): bool
    {
        if ($requirements === []) {
            return false;
        }

        foreach ($requirements as $req) {
            if (!is_array($req) || !$this->allows($req)) {
                return false;
            }
        }

        return true;
    }
}
