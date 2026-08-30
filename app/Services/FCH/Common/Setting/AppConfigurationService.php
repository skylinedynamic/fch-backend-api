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

        foreach ($settings as $key => $val) {
            $val = $val === '' ? null : $val;
            $data[$key] = $val;

            if (in_array($key, AppConfiguration::IMAGE_KEYS)) {
                $data[$key . '_full_url'] = $val ? encodeUrl(asset('storage/' . ltrim($val, '/'))) : null;
            }
        }

        // Fallback for image keys if null or missing
        foreach (AppConfiguration::IMAGE_KEYS as $imageKey) {
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

        return $data;
    }
}
