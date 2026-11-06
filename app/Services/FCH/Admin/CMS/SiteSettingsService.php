<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\Common\AppConfiguration;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

class SiteSettingsService
{
    use UploadsFiles;

    private const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'primary_logo',
        'secondary_logo',
        'favicon',
        'website_favicon',
    ];

    /**
     * Get value of a config key for a specific entity
     */
    public function getSetting($partnerId=null)
    {
        return AppConfiguration::where('module', AppConfiguration::MODULE_WEBSITE)->where('partner_id', $partnerId)->get();
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

        $rows = AppConfiguration::where('module', AppConfiguration::MODULE_WEBSITE)
            ->where('partner_id', $partnerId)
            ->pluck('value', 'key')
            ->toArray();

        $data=[]; 

        foreach ($rows as $key => $row) {
            if ($key == 'social_links') {
                $data[$key] = json_decode($row ?? '[]', true) ?: [];
            } elseif (in_array($key, self::IMAGE_KEYS, true)) {
                $data[$key] = $this->buildImageUrl($key, $row);
            } else {
                // The reCAPTCHA secret is reported as set, never sent (BUG-022).
                $data[$key] = AppConfiguration::forDisplay($key, $row);
            }
        }

        return $data;

    }

    /**
     * Update site settings.
     */
    public function updateSettings(?int $partnerId, array $data): array
    {
        DB::beginTransaction();
        try {
            foreach ($data as $key => $value) {
                if (AppConfiguration::keepsStoredSecret($key, $value)) {
                    // Masked or left blank: the form never holds the secret.
                    continue;
                }

                if ($key === 'social_links') {
                    $links = is_array($value)
                        ? $value
                        : json_decode($value, true);

                    if (is_array($links)) {
                        $cleaned = array_map(function ($link) {
                            return [
                                'platform' => (isset($link['platform']) && $link['platform'] !== '') ? trim($link['platform']) : null,
                                'url' => (isset($link['url']) && $link['url'] !== '') ? trim($link['url']) : null,
                                'icon' => (isset($link['icon']) && $link['icon'] !== '') ? trim($link['icon']) : null,
                            ];
                        }, $links);
                        $filtered = array_values(array_filter($cleaned ?? [], function ($link) {
                            return !empty($link['platform']) || !empty($link['url']);
                        }));
                        AppConfiguration::set('social_links', json_encode($filtered), AppConfiguration::MODULE_WEBSITE, $partnerId);
                    }
                } elseif (in_array($key, self::IMAGE_KEYS, true) && $value instanceof UploadedFile) {
                    $existing = AppConfiguration::get($key, null, AppConfiguration::MODULE_WEBSITE, $partnerId);
                    if ($existing) {
                        $this->deleteFile($existing, Disk::UPLOADS);
                    }

                    $prefix = match ($key) {
                        'header_logo' => 'h',
                        'footer_logo' => 'f',
                        'website_favicon' => 'wfav',
                        default => $key,
                    };
                    $filename = time() . "_{$prefix}_" . $value->getClientOriginalName();
                    $upload = $this->uploadFile($value, Folder::SITE_SETTINGS, Disk::UPLOADS, $filename);
                    AppConfiguration::set($key, $upload['path'], AppConfiguration::MODULE_WEBSITE, $partnerId);
                } else {
                    AppConfiguration::set($key, $value, AppConfiguration::MODULE_WEBSITE, $partnerId);
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


    private function buildImageUrl(string $key, ?string $value): ?string
    {
        if (!$value || $value === 'null' || str_ends_with($value, '/null') || !in_array($key, self::IMAGE_KEYS, true)) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return encodeUrl(asset('storage/' . $value));
    }
}
