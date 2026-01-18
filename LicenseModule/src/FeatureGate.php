<?php

declare(strict_types=1);

namespace LicenseModule;

/**
 * Feature gating based on license tier and addons
 *
 * Manages module availability based on license tier level and add-ons.
 * Tiers are hierarchical - higher tiers include all modules from lower tiers.
 */
class FeatureGate
{
    /**
     * Default tier configuration (from FlowerShop)
     * Maps tier level to module list
     */
    private const DEFAULT_TIERS = [
        1 => ['name' => 'Core', 'modules' => [
            'catalog', 'orders', 'users', 'vat_validation',
            'activity_audit', 'email_templates', 'favorites',
        ]],
        2 => ['name' => 'Standard', 'modules' => [
            'membership', 'invoicing', 'payment_methods', 'custom_attributes',
        ]],
        3 => ['name' => 'Advanced', 'modules' => ['reports']],
        4 => ['name' => 'Pro', 'modules' => ['delivery', 'storage_management']],
    ];

    /**
     * Default addon configuration (from FlowerShop)
     * Maps addon feature_key to module list
     */
    private const DEFAULT_ADDONS = [
        'analytics' => ['tracking'],
        'messageboard' => ['messageboard'],
        'mailchimp' => ['mailchimp'],
    ];

    /** @var array<int, array{name: string, modules: string[]}> Tier configuration */
    private array $tiers;

    /** @var array<string, string[]> Addon configuration */
    private array $addons;

    /** @var array|null Cached license features */
    private ?array $licenseCache = null;

    /** @var callable License data provider */
    private $licenseProvider;

    /**
     * @param callable $licenseProvider Callable that returns license features array
     * @param array|null $tiers Custom tier configuration (null for defaults)
     * @param array|null $addons Custom addon configuration (null for defaults)
     */
    public function __construct(
        callable $licenseProvider,
        ?array $tiers = null,
        ?array $addons = null
    ) {
        $this->licenseProvider = $licenseProvider;
        $this->tiers = $tiers ?? self::DEFAULT_TIERS;
        $this->addons = $addons ?? self::DEFAULT_ADDONS;
    }

    /**
     * Check if a module is enabled
     *
     * @param string $module Module identifier
     * @return bool
     */
    public function hasModule(string $module): bool
    {
        $license = $this->getLicenseData();

        // Legacy license format (no tier field) - enable all modules
        if ($license === null || !isset($license['tier'])) {
            return true;
        }

        $tierLevel = $this->extractTierLevel($license['tier']);
        $enabledAddons = $license['addons'] ?? [];

        // Check if module is enabled by tier level
        if ($this->isModuleInTier($module, $tierLevel)) {
            return true;
        }

        // Check if module is in enabled add-ons
        foreach ($enabledAddons as $addon) {
            $addonKey = $addon['feature_key'] ?? null;

            if ($addonKey !== null && isset($this->addons[$addonKey])) {
                if (in_array($module, $this->addons[$addonKey], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all enabled modules
     *
     * @return string[] List of enabled module identifiers
     */
    public function getEnabledModules(): array
    {
        $license = $this->getLicenseData();

        // Legacy license - all modules enabled
        if ($license === null || !isset($license['tier'])) {
            return $this->getAllModules();
        }

        $tierLevel = $this->extractTierLevel($license['tier']);
        $enabledAddons = $license['addons'] ?? [];

        $enabled = [];

        // Add tier modules based on level (hierarchical)
        foreach ($this->tiers as $level => $tierConfig) {
            if ($tierLevel >= $level) {
                $enabled = array_merge($enabled, $tierConfig['modules']);
            }
        }

        // Add addon modules
        foreach ($enabledAddons as $addon) {
            $addonKey = $addon['feature_key'] ?? null;
            if ($addonKey !== null && isset($this->addons[$addonKey])) {
                $enabled = array_merge($enabled, $this->addons[$addonKey]);
            }
        }

        return array_unique($enabled);
    }

    /**
     * Get all available modules
     *
     * @return string[]
     */
    public function getAllModules(): array
    {
        $all = [];

        foreach ($this->tiers as $tierConfig) {
            $all = array_merge($all, $tierConfig['modules']);
        }

        foreach ($this->addons as $modules) {
            $all = array_merge($all, $modules);
        }

        return array_unique($all);
    }

    /**
     * Get current tier information
     *
     * @return array|null Tier object {slug, name, level} or null for legacy license
     */
    public function getTier(): ?array
    {
        $license = $this->getLicenseData();

        if ($license === null || !isset($license['tier'])) {
            return null;
        }

        $tierData = $license['tier'];

        if (is_array($tierData)) {
            return [
                'slug' => $tierData['slug'] ?? null,
                'name' => $tierData['name'] ?? null,
                'level' => (int) ($tierData['level'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * Get current tier level
     *
     * @return int Tier level (0 if legacy/invalid)
     */
    public function getTierLevel(): int
    {
        $tier = $this->getTier();

        return $tier['level'] ?? 0;
    }

    /**
     * Check if an addon is enabled
     *
     * @param string $addonKey Addon feature key
     * @return bool
     */
    public function hasAddon(string $addonKey): bool
    {
        $license = $this->getLicenseData();

        // Legacy license - all addons enabled
        if ($license === null || !isset($license['tier'])) {
            return true;
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

        // Legacy license - all addons enabled
        if ($license === null || !isset($license['tier'])) {
            return array_keys($this->addons);
        }

        $enabledAddons = $license['addons'] ?? [];

        return array_filter(array_map(
            fn($addon) => $addon['feature_key'] ?? null,
            $enabledAddons
        ));
    }

    /**
     * Get minimum tier level required for a module
     *
     * @param string $module Module identifier
     * @return int|null Required tier level or null if addon-only or not found
     */
    public function getModuleRequiredLevel(string $module): ?int
    {
        foreach ($this->tiers as $level => $tierConfig) {
            if (in_array($module, $tierConfig['modules'], true)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Clear license cache
     */
    public function clearCache(): void
    {
        $this->licenseCache = null;
    }

    /**
     * Get license data from provider
     *
     * @return array|null
     */
    private function getLicenseData(): ?array
    {
        if ($this->licenseCache === null) {
            $features = ($this->licenseProvider)();

            // null or legacy format ['all']
            if ($features === null || (is_array($features) && !isset($features['tier']))) {
                $this->licenseCache = null;
            } else {
                $this->licenseCache = [
                    'tier' => $features['tier'] ?? null,
                    'addons' => $features['addons'] ?? [],
                ];
            }
        }

        return $this->licenseCache;
    }

    /**
     * Check if a module is enabled by tier level
     *
     * @param string $module Module identifier
     * @param int $tierLevel Current license tier level
     * @return bool
     */
    private function isModuleInTier(string $module, int $tierLevel): bool
    {
        foreach ($this->tiers as $level => $tierConfig) {
            if (in_array($module, $tierConfig['modules'], true)) {
                return $tierLevel >= $level;
            }
        }

        return false;
    }

    /**
     * Extract tier level from tier data
     *
     * @param mixed $tierData Tier object or string
     * @return int
     */
    private function extractTierLevel(mixed $tierData): int
    {
        if (is_array($tierData)) {
            return (int) ($tierData['level'] ?? 0);
        }

        // Legacy string format (core, standard, advanced, pro)
        if (is_string($tierData)) {
            return match ($tierData) {
                'core' => 1,
                'standard' => 2,
                'advanced' => 3,
                'pro' => 4,
                default => 0,
            };
        }

        return 0;
    }
}
