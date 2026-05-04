<?php

namespace App\Services\Global\Website\CMS;

use App\Models\Common\AppConfiguration;
use App\Traits\UploadsFiles;

class WebsiteSiteSettingsService
{
    use UploadsFiles;

    private const ENTITY_TYPE = 'App\\Models\\Partner\\Partner';
    private const DEFAULT_ENTITY_ID = 1;

    private const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'partner_logo',
        'favicon',
    ];

    private const TEXT_KEYS = [
        'site_name',
        'site_slogan',
        'address',
        'phone_number',
        'email',
        'map_iframe',
        'google_measurement_id',
        'facebook_pixel_id',
        'recaptcha_site_key',
        'recaptcha_secret_key',
    ];

    /**
     * Get the website site settings.
     * Default entity_id is 1.
     */
    public function getSettings(): array
    {
        $rows = AppConfiguration::where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', self::DEFAULT_ENTITY_ID)
            ->get()
            ->keyBy('key');

        $data = ['id' => self::DEFAULT_ENTITY_ID];

        foreach (array_merge(self::TEXT_KEYS, self::IMAGE_KEYS, ['social_links']) as $key) {
            $raw = $rows->has($key) ? $rows[$key]->value : null;
            
            if ($key === 'social_links') {
                $data[$key] = json_decode($raw ?? '[]', true) ?: [];
            } elseif (in_array($key, self::IMAGE_KEYS, true)) {
                $data[$key] = $this->buildImageUrl($key, $raw);
            } else {
                $data[$key] = $raw;
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
