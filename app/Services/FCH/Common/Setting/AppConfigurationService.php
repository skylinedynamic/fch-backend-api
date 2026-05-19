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
    public function getBrandingSettings(?int $partnerId = null, bool $useFallback = true): array
    {
        $module = AppConfiguration::MODULE_BRANDING;

        // 1. Get configurations for the specific partner
        $rows = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', $module)
            ->get()
            ->keyBy('key');

        // 2. Get configurations for the HOST as fallback
        $hostPartner = Partner::where('is_host', true)->first();
        $hostId = $hostPartner?->id;
        $hostRows = [];

        if ($useFallback && $hostId && $hostId != $partnerId) {
            $hostRows = AppConfiguration::where('partner_id', $hostId)
                ->where('module', $module)
                ->get()
                ->keyBy('key');
        }

        $textKeys = [
            'site_name',
            'site_slogan',
            'primary_color',
            'secondary_color',
            'meta_title',
        ];

        $imageKeys = [
            'header_logo',
            'footer_logo',
            'primary_logo',
            'secondary_logo',
            'favicon',
            'website_favicon',
        ];

        $data = [];
        foreach ($textKeys as $key) {
            $val = null;
            if ($rows->has($key) && !is_null($rows[$key]->value) && $rows[$key]->value !== '') {
                $val = $rows[$key]->value;
            } else {
                // For site_name, try to fallback to Partner company_name if it's a White-Label partner
                if ($key === 'site_name' && $partnerId) {
                    $partner = Partner::find($partnerId);
                    if ($partner && $partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
                        $val = $partner->company_name;
                    }
                }

                // If still null, use Host fallback
                if (is_null($val) && isset($hostRows[$key]) && !is_null($hostRows[$key]->value) && $hostRows[$key]->value !== '') {
                    $val = $hostRows[$key]->value;
                }
            }
            $data[$key] = $val;
        }

        foreach ($imageKeys as $key) {
            $path = null;
            if ($rows->has($key) && !empty($rows[$key]->value)) {
                $path = $rows[$key]->value;
            } elseif (isset($hostRows[$key]) && !empty($hostRows[$key]->value)) {
                $path = $hostRows[$key]->value;
            }

            if ($path && !str_starts_with($path, 'http')) {
                $data[$key] = encodeUrl(asset('storage/' . ltrim($path, '/')));
            } else {
                $data[$key] = $path;
            }
        }

        $data['is_white_label'] = false;
        if ($partnerId) {
            $partner = Partner::find($partnerId);
            if ($partner && $partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
                $data['is_white_label'] = true;
            }
        }

        return $data;
    }
}
