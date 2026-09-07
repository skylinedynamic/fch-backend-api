<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;

class AppConfigurationService
{
    /**
     * Get a configuration value by key and module.
     */
    public function get(string $key, $default = null, ?string $module = null, ?int $partnerId = null)
    {
        return AppConfiguration::get($key, $default, $module, $partnerId);
    }

    /**
     * Set a configuration value for a key and module.
     */
    public function set(string $key, $value, ?string $module = null, ?int $partnerId = null)
    {
        return AppConfiguration::set($key, $value, $module, $partnerId);
    }

    /**
     * Get configurations belonging to a specific module.
     */
    public function getByModule(string $module, ?int $partnerId = null): array
    {
        return AppConfiguration::query()
            ->where('module', $module)
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get multiple configurations by keys.
     */
    public function getMultiple(array $keys, ?string $module = null, ?int $partnerId = null): array
    {
        return AppConfiguration::query()
            ->whereIn('key', $keys)
            ->when($module, fn($q) => $q->where('module', $module))
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all configurations.
     */
    public function getAll(?int $partnerId = null): array
    {
        return AppConfiguration::query()
            ->when($partnerId, fn($q) => $q->where('partner_id', $partnerId))
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get branding settings with custom fallbacks and white-label checks.
     */
    public function getBrandingSettings($partnerId = null): array
    {
        $hostSettings = AppConfiguration::where('partner_id', Partner::host()?->id)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->pluck('value', 'key');

        $settings = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', AppConfiguration::MODULE_BRANDING)
            ->pluck('value', 'key');

        $partner = $partnerId ? Partner::find($partnerId) : null;

        $data = [];

        $imageKeys = array_unique(array_merge(
            AppConfiguration::IMAGE_KEYS,
            AppConfiguration::BRANDING_IMAGE_KEYS
        ));

        foreach ($settings as $key => $val) {
            $val = $val === '' ? null : $val;
            $data[$key] = $val;

            if (in_array($key, $imageKeys)) {
                $data[$key . '_full_url'] = $val ? encodeUrl(asset('storage/' . ltrim($val, '/'))) : null;
            }
        }

        // Fallback for image keys if null or missing
        foreach ($imageKeys as $imageKey) {
            if (($data[$imageKey] ?? null) === null) {
                $hostVal = $hostSettings[$imageKey] ?? null;
                $hostVal = $hostVal === '' ? null : $hostVal;
                if ($hostVal !== null) {
                    $data[$imageKey] = $hostVal;
                    $data[$imageKey . '_full_url'] = encodeUrl(asset('storage/' . ltrim($hostVal, '/')));
                } else {
                    $data[$imageKey] = null;
                    $data[$imageKey . '_full_url'] = null;
                }
            }
        }

        // A partner who has not re-uploaded under the surface names keeps their
        // logos: each key walks its fallback chain until one resolves. Applied in
        // two passes so a chain that points at another surface key (mail borrowing
        // the sidebar logo) still sees that key's own fallback filled in.
        for ($pass = 0; $pass < 2; $pass++) {
            foreach (AppConfiguration::BRANDING_LOGO_FALLBACKS as $newKey => $chain) {
                if (($data[$newKey] ?? null) !== null) {
                    continue;
                }

                foreach ((array) $chain as $fallbackKey) {
                    if (($data[$fallbackKey] ?? null) !== null) {
                        $data[$newKey] = $data[$fallbackKey];
                        $data[$newKey . '_full_url'] = $data[$fallbackKey . '_full_url'] ?? null;
                        break;
                    }
                }
            }
        }

        if (($data['site_name'] ?? null) === null) {
            $hostSiteName = $hostSettings['site_name'] ?? null;
            $data['site_name'] = $hostSiteName === '' ? null : $hostSiteName;
        }

        if (empty($data['primary_color']) || $data['primary_color'] === 'null') {
            $hostPrimary = $hostSettings['primary_color'] ?? null;
            $data['primary_color'] = $hostPrimary === '' ? null : $hostPrimary;
        }

        if (empty($data['secondary_color']) || $data['secondary_color'] === 'null') {
            $hostSecondary = $hostSettings['secondary_color'] ?? null;
            $data['secondary_color'] = $hostSecondary === '' ? null : $hostSecondary;
        }

        // Remaining brand text and colour settings. Each is left null when neither the
        // partner nor the host set one, so the portals derive a readable value instead
        // of falling back to a hard coded colour that may not suit the brand.
        $inheritedKeys = [
            'site_slogan',
            'topbar_bg_color',
            'topbar_text_color',
            'sidebar_bg_color',
            'sidebar_text_color',
            'sidebar_icon_color',
            'sidebar_hover_bg_color',
            'sidebar_text_hover_color',
            'sidebar_icon_hover_color',
            'sidebar_active_bg_color',
            'sidebar_active_text_color',
            'sidebar_active_icon_color',
        ];

        foreach ($inheritedKeys as $inheritedKey) {
            if (empty($data[$inheritedKey]) || $data[$inheritedKey] === 'null') {
                $hostValue = $hostSettings[$inheritedKey] ?? null;
                $data[$inheritedKey] = $hostValue === '' ? null : $hostValue;
            }
        }

        // Whether this partner is white-labelled is a property of the partner, not an
        // editable setting. The borrower portal reads it to decide whose name to show.
        $data['is_white_label'] = $partner
            ? ($partner->is_host || $partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL)
            : false;

        return $data;
    }
}
