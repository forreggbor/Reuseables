# Host Gating Integration Guide

## Principle

LicenseModule is the **single source of truth** for license tier/addon/feature data. It is the
only component that talks to the license server, and it evaluates every gating decision directly
from what the server sent — there is no local tier→module or addon→module table anywhere in this
module, and none should exist in the host either.

A host never re-implements tier/addon logic. Instead, at each point where a feature needs gating,
the host states **what that feature requires** (a specific addon, a tier, a minimum tier level, or
a feature key) and calls the corresponding LicenseModule method. The module answers `true`/`false`;
the host acts on that answer (render, redirect, block).

If you find yourself writing a local `MODULE_LEVELS`, `ADDON_MODULES`, or similar array in host
code, that is exactly the pattern this refactor removed — don't reintroduce it. See
[`LEGACY-TIER-ADDON-SPEC.md`](LEGACY-TIER-ADDON-SPEC.md) for what such tables looked like in the
projects that predate this model.

## Two license modes

The license server supports two ways of granting features to a license, and **both already
collapse into the same flat shape** that LicenseModule consumes — a host writes **one** gating
style regardless of which mode a given license actually uses:

1. **Tier-based.** The tier itself carries its own JSON list of feature keys, and tiers are
   hierarchical (a higher tier's feature list already includes the lower tier's — the server
   resolves this, LicenseModule never re-derives a hierarchy). Additional addons — tier-dependent
   or tier-independent — each carry their own `feature_key` and layer on top. A tier's feature key
   may or may not have a corresponding addon object.
2. **Tier-less / addon-only.** A tier object still exists (slug/name/level may be present, e.g. a
   placeholder tier) but contributes **no** feature keys of its own — only the selected standalone
   addons' feature keys populate the resolved feature set. `getTier()` can legitimately return an
   object with no bearing on gating in this mode, or `null` if no tier is assigned at all.

Two of the three currently-integrated hosts (TrafficJournal, UniCMS) already operate in mode 2 —
see `LEGACY-TIER-ADDON-SPEC.md` for their addon lists.

**This is not the "legacy" case.** Legacy means the license has **no tier data at all** — the
historical `['all']` sentinel, or no license row. A tier-less-features license (mode 2) still has
a real `tier` object; gating evaluates truthfully against whatever addons/features were actually
granted, which may legitimately be nothing.

## `hasFeature()` vs `hasAddon()`

- **`hasFeature(string $key)`** — the *general-purpose gating check*. Evaluates against the
  server's flat, fully-resolved feature set (tier-granted + addon-granted feature keys combined).
  Works correctly for both license modes, and for tier-granted feature keys that have no matching
  addon object. **Use this for gating.**
- **`hasAddon(string $featureKey)`** — checks specifically whether a purchasable/marketed addon
  object exists in the license's `addons[]` array (which carries `name`/`description` for display).
  Use it for **presentation** — badges, upsell prompts, "you have the X add-on" messaging — not as
  the primary gate, since a tier can grant a feature key with no corresponding addon entry at all.

```php
// Gating a feature — use hasFeature()
if ($license->hasFeature('advanced_reports')) {
    // render the reports section
}

// Displaying which purchasable add-ons are active — use hasAddon() / getAddons()
foreach ($license->getAddons() as $addon) {
    echo $addon['name']; // "Advanced Analytics", etc.
}
```

## Caching — hosts don't need their own

Gating never contacts the remote license server. The server is only contacted by the periodic
`validate()` call; the result is persisted in the local `license_info` database row, and every
gating primitive reads that row, memoized once per request inside `FeatureGate`. Call
`hasFeature()`/`hasAddon()`/`allows()` as many times as you need per request — the first call does
one indexed database read, every subsequent call in the same request is served from memory. Do not
build your own caching layer around these calls; if you find gating slow, check whether the
`get_pdo` callable is doing something expensive, not whether you need to memoize the module.

## Legacy / no-tier-data license: deny-by-default

A license with no tier data at all (the `['all']` sentinel, or no license row) is treated as
**unknown, therefore denied**: `hasAddon()`, `hasFeature()`, `hasTier()`, `requireTierLevel()`, and
`allows()` all return `false`; `getEnabledAddons()`/`getFeatureKeys()` return `[]`.

This is a deliberate change from pre-2.0.0 behavior, where a legacy license implied unrestricted
access. **Do not rely on legacy meaning "everything enabled."** If a deployment genuinely needs
unrestricted access, express that on the license server as an explicit tier/feature/addon grant —
never by leaving the license's data empty.

## API surface

| Method | Returns | Notes |
|---|---|---|
| `getTier()` | `?array{slug,name,level,description}` | Passthrough. `null` for legacy or no-tier-assigned. |
| `getTierLevel()` | `int` | `0` if legacy or no tier. |
| `hasTier(string $slug)` | `bool` | Exact tier-slug match. |
| `requireTierLevel(int $minLevel)` | `bool` | Pure predicate — see below. |
| `hasAddon(string $featureKey)` | `bool` | Checks the addon list — see "hasFeature vs hasAddon" above. |
| `getEnabledAddons()` | `string[]` | Enabled addon feature keys. |
| `getAddons()` | `array` of `{feature_key,name,slug,description}` | Full addon rows, for display. |
| `getFeatureKeys()` | `string[]` | The authoritative flat enabled-feature set. |
| `hasFeature(string $key)` | `bool` | General-purpose gating — see above. |
| `getPackage()` | `?array{id,name,slug}` | API-only; not shown on the admin page. |
| `allows(array $requirement)` | `bool` | Composed requirement — see contract below. |

`requireTierLevel()` is a **pure predicate** — it does not enforce, block, or redirect anything. It
only answers "is the current tier level at least this?". The host is responsible for acting on the
boolean result:

```php
if (!$license->requireTierLevel(3)) {
    // the host decides what happens here: redirect, 403, upsell banner, etc.
    return $this->renderUpsell();
}
```

## The `allows()` contract

`allows(array $requirement)` is a **deny-by-default dispatcher**. Exactly one recognized top-level
key is expected per call:

| Key | Value type | Delegates to |
|---|---|---|
| `addon` | `string` | `hasAddon()` |
| `tier` | `string` | `hasTier()` |
| `min_tier_level` | `int` | `requireTierLevel()` |
| `feature` | `string` | `hasFeature()` |
| `any_of` | `array` of requirements | `true` if **any** sub-requirement matches |
| `all_of` | `array` of requirements | `true` only if **all** sub-requirements match (an empty list is deny-by-default, never vacuously true) |

An **empty requirement, an unrecognized key, or more than one top-level key always evaluates to
`false`.** There is no fall-through to `true` for malformed input.

```php
// Safe — single recognized key
$license->allows(['feature' => 'advanced_reports']);        // true/false
$license->allows(['min_tier_level' => 3]);                  // true/false

// Safe — composed
$license->allows(['any_of' => [
    ['addon' => 'seo'],
    ['min_tier_level' => 4],
]]);

// Always false — do not rely on any of these "working"
$license->allows([]);                                        // empty requirement
$license->allows(['bogus_key' => 'x']);                       // unrecognized key
$license->allows(['addon' => 'seo', 'tier' => 'pro']);        // more than one top-level key
$license->allows(['all_of' => []]);                           // empty all_of is NOT vacuously true
```

## Gating patterns

### Inline

```php
if ($license->hasFeature('anpr_camera')) {
    // route handler logic
}
```

### Route middleware

Map routes to a requirement and call `allows()` — no local tier/module table, just the mapping of
*which* requirement each route needs:

```php
private const ROUTE_REQUIREMENTS = [
    '/admin/cameras' => ['feature' => 'anpr_camera'],
    '/admin/reports' => ['min_tier_level' => 3],
];

public function handle(string $uri): bool
{
    foreach (self::ROUTE_REQUIREMENTS as $prefix => $requirement) {
        if (str_starts_with($uri, $prefix) && !$this->license->allows($requirement)) {
            return $this->blockAccess();
        }
    }
    return true;
}
```

### Admin page

`renderAdminPage()` already displays tier, addon badges, and the resolved feature list — no host
gating code is needed for the license admin screen itself; just protect the route (auth/CSRF are
the host's responsibility, see the Security Boundary note in the main README).

## Migration recipes (future host work — not performed as part of this refactor)

These are **not** applied to the host projects as part of the 2.0.0 module refactor; apply them
when integrating each project against 2.0.0.

### TrafficJournal

- Remove the `'addons' => self::ADDON_MODULES` config passed to `new LicenseModule(...)`.
- Rewrite `LicenseMiddleware::hasModule()` to call `hasFeature($module)` (or `hasAddon($module)` if
  the check is specifically about a purchasable add-on) instead of the removed module-mapping call.
  All existing call sites through the wrapper keep working unchanged.
- Replace `getEnabledModules()` (used in the License settings tab) with a host-built list derived
  from `getAllModulesWithStatus()` / `getEnabledAddons()`.

### UniCMS

- Remove the dead `'tiers'`/`'addons'` config passed to `new LicenseModule(...)`.
- Delete the unused `LicenseService::hasModule()` wrapper (it has no call sites — gating already
  goes through `hasAddon()`, which is unaffected).

### JupitERP

- `ModuleService` currently reimplements tier/addon→module mapping entirely host-side, reading raw
  `features` JSON. Migrate it to call `hasFeature()`/`allows()` directly instead of decoding
  `features` and re-deriving tier levels/addon lists locally — this removes the last hardcoded
  `MODULE_LEVELS`/`ADDON_MODULES` tables from the codebase (see the gaps noted in
  `LEGACY-TIER-ADDON-SPEC.md`, which this migration also resolves for free since the module will no
  longer be able to drift out of sync with the license server's actual addon list).
