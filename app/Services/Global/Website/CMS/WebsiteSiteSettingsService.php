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
        $user = auth('api')->user();
        $entityType = $user->entity_type ?? \App\Models\Partner\Partner::class;
        $entityId = request()->input('entity_id') ?: ($user->entity_id ?? $user->partner_id ?? null);

        // Normalize entity type
        if ($entityType === 'site-settings' || !str_contains($entityType, '\\')) {
            $entityType = \App\Models\Partner\Partner::class;
        }

        // If no ID provided and not logged in, try to resolve from host/domain (fallback to host 1 for now)
        if (!$entityId) {
            $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
            $entityId = $hostPartner?->id ?: 1;
            $entityType = \App\Models\Partner\Partner::class;
        }

        // 1. Get configurations for the specific entity (Partner)
        $rows = AppConfiguration::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get()
            ->keyBy('key');

        // 2. Get configurations for the HOST as fallback
        $hostPartner = \App\Models\Partner\Partner::where('is_host', 1)->first();
        $hostId = $hostPartner?->id;
        $hostRows = [];

        // Only fetch host settings if current is not host or is a different entity type
        if ($hostId && ($entityType !== \App\Models\Partner\Partner::class || $hostId != $entityId)) {
            $hostRows = AppConfiguration::where('entity_type', \App\Models\Partner\Partner::class)
                ->where('entity_id', $hostId)
                ->get()
                ->keyBy('key');
        }

        $data = ['id' => $entityId];

        foreach (array_merge(self::TEXT_KEYS, self::IMAGE_KEYS, ['social_links']) as $key) {
            if ($key === 'social_links') {
                $raw = $rows->has($key) ? $rows[$key]->value : (isset($hostRows[$key]) ? $hostRows[$key]->value : null);
                $data[$key] = json_decode($raw ?? '[]', true) ?: [];
                continue;
            }

            // Get value with fallback
            $val = null;
            if ($rows->has($key) && !empty($rows[$key]->value)) {
                $val = $rows[$key]->value;
            } elseif (isset($hostRows[$key]) && !empty($hostRows[$key]->value)) {
                $val = $hostRows[$key]->value;
            }

            if (in_array($key, self::IMAGE_KEYS, true)) {
                $data[$key] = $this->buildImageUrl($key, $val);
                // Backward compatibility for 'favicon' key if needed by frontend
                if ($key === 'partner_favicon') {
                    $data['favicon'] = $data[$key];
                }
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
