<?php

namespace App\Services\Global\Website\CMS;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Traits\UploadsFiles;

class WebsiteSiteSettingsService
{
    use UploadsFiles;

    private const ENTITY_TYPE = 'App\\Models\\Partner\\Partner';
    private const DEFAULT_ENTITY_ID = 1;

    private const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'primary_logo',
        'secondary_logo',
        'favicon',
        'website_favicon',
    ];

    private const TEXT_KEYS = [
        'site_name',
        'site_slogan',
        'address',
        'phone_number',
        'email',
        'primary_color',
        'secondary_color',
        'map_iframe',
        'google_measurement_id',
        'facebook_pixel_id',
        'recaptcha_site_key',
        'recaptcha_secret_key',
    ];

    /**
     * Get the website site settings.
     */
    public function getSettings(): array
    {
        // Resolve the host partner (is_host = 1)
        $hostPartner = Partner::where('is_host', 1)->firstOrFail();

        // Fetch host site settings as a flat key => value array
        $hostRows = AppConfiguration::where('partner_id', $hostPartner->id)
            ->where('module', AppConfiguration::MODULE_WEBSITE)
            ->pluck('value', 'key')
            ->toArray();

        $data = ['id' => $hostPartner->id];

        foreach (array_merge(self::TEXT_KEYS, self::IMAGE_KEYS, ['social_links']) as $key) {
            if ($key === 'social_links') {
                $raw = $hostRows[$key] ?? null;
                $data[$key] = json_decode($raw ?? '[]', true) ?: [];
                continue;
            }

            $val = !empty($hostRows[$key]) ? $hostRows[$key] : null;

            if (in_array($key, self::IMAGE_KEYS, true)) {
                // buildImageUrl returns the full URL (storage or external)
                $data[$key] = $this->buildImageUrl($key, $val);
            } else {
                $data[$key] = $val;
            }
        }

        return $data;
    }

    private function buildImageUrl(string $key, ?string $value): ?string
    {
        if (!$value || $value === 'null' || str_ends_with($value, '/null')) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }
        $path = ltrim($value, '/');
        if (!str_starts_with($path, 'site-settings/')) {
            $path = 'site-settings/' . $path;
        }
        return encodeUrl(asset('storage/' . $path));
    }
}
