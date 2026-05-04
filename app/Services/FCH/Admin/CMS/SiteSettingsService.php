<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\Common\AppConfiguration;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiteSettingsService
{
    use UploadsFiles;

    private const ENTITY_TYPE = 'App\\Models\\Partner\\Partner';
    private const DEFAULT_ENTITY_ID = 1;

    private const TEXT_KEYS = [
        'site_name',
        'site_slogan',
        'address',
        'phone_number',
        'email',
        'map_iframe',
        'agreement_signing_enabled',
        'google_measurement_id',
        'recaptcha_site_key',
        'recaptcha_secret_key',
    ];

    private const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'partner_logo',
        'favicon',
    ];

    /**
     * Get value of a config key for a specific entity
     */
    public function getSetting(string $key, ?string $entityType = null, ?int $entityId = null, $default = '0')
    {
        return AppConfiguration::get($key, $default, $entityType, $entityId);
    }

    /**
     * Save/Toggle value of a config key for a specific entity
     */
    public function saveSetting(string $key, $value, ?string $entityType = null, ?int $entityId = null)
    {
        return AppConfiguration::set($key, $value, $entityType, $entityId);
    }

    /**
     * Get all site settings for an admin (usually global settings).
     */
    public function getSettings(?int $partnerId = null): array
    {
        $entityId = $partnerId ?? self::DEFAULT_ENTITY_ID;

        $rows = AppConfiguration::where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $entityId)
            ->get()
            ->keyBy('key');

        $data = ['id' => $entityId, 'partner_id' => $partnerId];

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

        $data['created_at'] = $rows->first()?->created_at;
        $data['updated_at'] = $rows->last()?->updated_at;

        return $data;
    }

    /**
     * Update site settings.
     */
    public function updateSettings(?int $partnerId, array $data): array
    {
        $entityId = $partnerId ?? self::DEFAULT_ENTITY_ID;

        DB::beginTransaction();
        try {
            // ── Social links ─────────────────────────────────────────────────
            if (isset($data['social_links'])) {
                $links = is_array($data['social_links'])
                    ? $data['social_links']
                    : json_decode($data['social_links'], true);

                if (is_array($links)) {
                    $cleaned = array_map(function($link) {
                        return [
                            'platform' => (isset($link['platform']) && $link['platform'] !== '') ? trim($link['platform']) : null,
                            'url' => (isset($link['url']) && $link['url'] !== '') ? trim($link['url']) : null,
                            'icon' => (isset($link['icon']) && $link['icon'] !== '') ? trim($link['icon']) : null,
                        ];
                    }, $links);
                    $filtered = array_values(array_filter($cleaned ?? [], function ($link) {
                        return !empty($link['platform']) || !empty($link['url']);
                    }));
                    AppConfiguration::set('social_links', json_encode($filtered), self::ENTITY_TYPE, $entityId);
                }
            }

            // ── Text fields ──────────────────────────────────────────────────
            foreach (self::TEXT_KEYS as $key) {
                if (array_key_exists($key, $data) && !($data[$key] instanceof UploadedFile)) {
                    AppConfiguration::set($key, $data[$key] ?? '', self::ENTITY_TYPE, $entityId);
                }
            }

            // ── Image fields ─────────────────────────────────────────────────
            foreach (self::IMAGE_KEYS as $key) {
                if (!isset($data[$key])) {
                    continue;
                }

                if ($data[$key] instanceof UploadedFile) {
                    $existing = AppConfiguration::get($key, null, self::ENTITY_TYPE, $entityId);
                    if ($existing) {
                        $this->deleteFile($existing, 'public');
                    }

                    $prefix  = match ($key) {
                        'header_logo'  => 'h',
                        'footer_logo'  => 'f',
                        'partner_logo' => 'p',
                        'favicon'      => 'fav',
                        default        => $key,
                    };
                    $filename = time() . "_{$prefix}_" . $data[$key]->getClientOriginalName();
                    $upload   = $this->uploadFile('site-settings', $data[$key], 'public', $filename);
                    AppConfiguration::set($key, $upload['path'], self::ENTITY_TYPE, $entityId);

                } elseif (isset($data[$key])) {
                    AppConfiguration::set($key, $this->normalizePath($data[$key]), self::ENTITY_TYPE, $entityId);
                }
            }

            DB::commit();
            return $this->getSettings($partnerId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Site settings update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function normalizePath($path): ?string
    {
        if (!$path || $path === 'null' || $path === 'undefined') {
            return null;
        }

        $path = ltrim((string)$path, '/');
        if (!str_starts_with($path, 'site-settings/')) {
            return 'site-settings/' . $path;
        }
        return $path;
    }

    private function buildImageUrl(string $key, ?string $value): ?string
    {
        if (!$value || $value === 'null' || str_ends_with($value, '/null') || !in_array($key, self::IMAGE_KEYS, true)) {
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
