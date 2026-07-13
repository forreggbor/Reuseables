# Legacy Tier/Addon Specification (historical record)

## Overview

Before LicenseModule 2.0.0, this module carried a hardcoded, project-specific tier→module and
addon→module mapping (`FeatureGate::DEFAULT_TIERS` / `DEFAULT_ADDONS`), overridable per host via
`tiers`/`addons` constructor config. As of 2.0.0 this mapping has been **removed entirely** —
LicenseModule now evaluates gating only from the license server's own resolved data (see
[`HOST-GATING-INTEGRATION.md`](HOST-GATING-INTEGRATION.md)).

This document is a **point-in-time snapshot** of what each consuming project's tier/addon
configuration looked like immediately before that removal, so the information is not lost. It is
**obsolete** the moment a given project migrates to the 2.0.0 gating model and stops relying on
its own hardcoded module lists — treat it as historical reference, not as current behavior.

---

## JupitERP

JupitERP does its own host-side gating in `app/services/ModuleService.php` — it never called this
module's tier/addon methods; it read `LicenseModule::getLicenseInfo()` and decoded the `features`
JSON itself.

### Tier levels (`ModuleService::MODULE_LEVELS`, hierarchical — higher level includes all lower)

| Level | Name (`TIER_NAMES`) | Modules |
|-------|----------------------|---------|
| 1 | core     | `catalog`, `orders`, `users`, `vat_validation`, `activity_audit`, `email_templates`, `favorites` |
| 2 | standard | `membership`, `invoicing`, `payment_methods`, `shipping_methods`, `custom_attributes`, `product_variants` |
| 3 | advanced | `reports`, `delivery`, `storage_management`, `consignment` |
| 4 | pro      | `supplier`, `incoming_goods`, `purchasing` |

### Addon → module map (`ModuleService::ADDON_MODULES`)

| Addon key (feature_key) | Modules unlocked |
|--------------------------|-------------------|
| `analytics` | `tracking` |
| `demo_mode` (`DemoSeedService::ADDON_NAME`) | *(none — gates a behavior, not a module)* |
| `mailchimp` | `mailchimp` |
| `messageboard` | `messageboard` |
| `seo` | `seo` |
| `supplier_portal` | `supplier_portal` |
| `termeloi_hozzaferes` | `supplier_portal` *(alias of `supplier_portal`)* |
| `woocommerce_import` | `woocommerce_import` |

### Current license-server addon list (JupitERP product, captured 2026-07-12)

| Display name (HU) | feature_key |
|---|---|
| Adatmentés | `backup_restore` |
| Admin üzenőfal | `messageboard` |
| Analitika | `analytics` |
| Beszállítói hozzáférés | `supplier_portal` |
| Csomagpont | `pickup_point` |
| Demo mód | `demo_mode` |
| MailChimp | `mailchimp` |
| Rendszeres frissítések | `updates` |
| SEO | `seo` |
| Szállítási módok | `shipping_methods` |
| Termék variációk | `product_variants` |
| WooCommerce import | `woocommerce_import` |

### Gaps / inconsistencies at time of capture

- The server addon list includes `backup_restore`, `pickup_point`, and `updates` — none of these
  have a corresponding entry in `ModuleService::ADDON_MODULES`. They existed on the license server
  but were not yet wired into JupitERP's host-side gating.
- `shipping_methods` and `product_variants` are **addons** on the license server, but JupitERP's
  `MODULE_LEVELS` lists them as **tier-2 (Standard) modules** instead. Under the pre-2.0.0 model
  this meant a license's tier level alone controlled these two features regardless of whether the
  corresponding addon was actually purchased — a discrepancy between the server's product model and
  the host's gating model that pre-dates this refactor.

---

## TrafficJournal

No tier configuration was ever defined host-side — `LicenseMiddleware` only supplied an
`addons` config to the module's (now-removed) tier/addon mapping; the module's own tier defaults
(JupitERP's, inherited as the fallback) were never exercised in practice. This matches license
server **mode 2** (tier-less/addon-only): TrafficJournal's licenses carry no tier-level feature
list, only addon selections.

### Addon → module map (`LicenseMiddleware::ADDON_MODULES`, all identity maps)

| Addon key (feature_key) | Module unlocked |
|---------------------------|-------------------|
| `anpr_camera` | `anpr_camera` |
| `mozgasnaplo` | `mozgasnaplo` |
| `patch_management` | `patch_management` |
| `gsm_gate_control` | `gsm_gate_control` |

### Route → module gating (`LicenseMiddleware::ROUTE_MODULE_MAP`)

| Route prefix | Required module |
|---|---|
| `/admin/cameras` | `anpr_camera` |
| `/admin/traffic` | `mozgasnaplo` |
| `/api/camera` | `anpr_camera` |
| `/admin/settings/patch-management` | `patch_management` |
| `/admin/gsm-devices` | `gsm_gate_control` |

---

## UniCMS

Also license server **mode 2** (tier-less/addon-only) — the configured tier is a placeholder with
no feature list of its own:

```php
'tiers' => [
    'standard' => ['level' => 1, 'name' => 'Standard', 'modules' => []],
],
'addons' => [],
```

Gating is 100% addon-driven via `LicenseService::hasAddon()` (a direct passthrough to the module,
unaffected by this refactor). Known addon keys referenced in host code at time of capture:
`evangelikus_napi_ige` (a content feature) and `updates` (patch/update eligibility), plus
additional addon keys resolved dynamically per active theme via `ThemeManager` (theme-declared
addon requirements are not enumerable statically — see `ThemeManager` for the current list).
UniCMS's `LicenseService::hasModule()` wrapper had zero call sites at time of capture — all gating
went through `hasAddon()`.
