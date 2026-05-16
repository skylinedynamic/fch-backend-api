<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Common\AppConfiguration;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;

class GeneralConfigurationsService
{
    use UploadsFiles;

    private const TEXT_KEYS = [
        'site_name',
        'site_slogan',
        'copyright_text',
        'primary_color',
        'secondary_color',
        'meta_title',
    ];

    private const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'primary_logo',
        'secondary_logo',
        'favicon',
        'website_favicon',
    ];

    /**
     * Get general branding configurations.
     */
    /**
     * Get general branding configurations.
     */
    public function show(?string $entityType = null, ?int $entityId = null, bool $useFallback = true): array
    {
        $user = auth()->user();
        $entityType = $entityType ?: ($user->entity_type ?? \App\Models\Partner\Partner::class);
        $entityId   = $entityId   ?: ($user->entity_id   ?? $user->partner_id);

        // Normalize entity type if it comes from frontend in a different format
        if ($entityType === 'site-settings' || !str_contains($entityType, '\\')) {
            $entityType = \App\Models\Partner\Partner::class;
        }

        // 1. Get configurations for the specific entity
        $rows = AppConfiguration::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get()
            ->keyBy('key');

        // 2. Get configurations for the HOST as fallback
        $hostPartner = \App\Models\Partner\Partner::where('is_host', true)->first();
        $hostId = $hostPartner?->id;
        $hostRows = [];
        
        if ($useFallback && $hostId && ($entityType !== \App\Models\Partner\Partner::class || $hostId != $entityId)) {
            $hostRows = AppConfiguration::where('entity_type', \App\Models\Partner\Partner::class)
                ->where('entity_id', $hostId)
                ->get()
                ->keyBy('key');
        }

        $data = [];
        foreach (self::TEXT_KEYS as $key) {
            $val = null;
            if ($rows->has($key) && !is_null($rows[$key]->value) && $rows[$key]->value !== '') {
                $val = $rows[$key]->value;
            } else {
                // For site_name, try to fallback to Partner company_name if it's a White-Label partner
                if ($key === 'site_name' && $entityType === \App\Models\Partner\Partner::class) {
                    $partner = \App\Models\Partner\Partner::find($entityId);
                    if ($partner && $partner->partner_type === \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
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

        foreach (self::IMAGE_KEYS as $key) {
            $path = null;
            if ($rows->has($key) && !empty($rows[$key]->value)) {
                $path = $rows[$key]->value;
            } elseif (isset($hostRows[$key]) && !empty($hostRows[$key]->value)) {
                $path = $hostRows[$key]->value;
            }

            if ($path && !str_starts_with($path, 'http')) {
                $data[$key] = $this->fileUrl($path, 'public');
            } else {
                $data[$key] = $path;
            }
        }

        $data['is_white_label'] = false;
        if ($entityType === \App\Models\Partner\Partner::class) {
            $partner = \App\Models\Partner\Partner::find($entityId);
            if ($partner && $partner->partner_type === \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL) {
                $data['is_white_label'] = true;
            }
        }

        return $data;
    }

    /**
     * Update general branding configurations.
     */
    public function update(array $data, ?string $entityType = null, ?int $entityId = null)
    {
        $user = auth()->user();
        $entityType = $entityType ?: ($user->entity_type ?? \App\Models\Partner\Partner::class);
        $entityId   = $entityId   ?: ($user->entity_id   ?? $user->partner_id);

        // Normalize entity type if it comes as a string like 'site-settings' from frontend
        if ($entityType === 'site-settings' || !str_contains($entityType, '\\')) {
            $entityType = \App\Models\Partner\Partner::class;
        }

        // ── Text fields ──────────────────────────────────────────────────
        foreach (self::TEXT_KEYS as $key) {
            if (array_key_exists($key, $data) && !($data[$key] instanceof UploadedFile)) {
                AppConfiguration::set($key, $data[$key] ?? '', $entityType, $entityId);
            }
        }

        // ── Image fields ─────────────────────────────────────────────────
        foreach (self::IMAGE_KEYS as $key) {
            if (!isset($data[$key])) {
                continue;
            }

            if ($data[$key] instanceof UploadedFile) {
                $existing = AppConfiguration::get($key, null, $entityType, $entityId);
                if ($existing) {
                    $this->deleteFile($existing, 'public');
                }

                $prefix = match ($key) {
                    'header_logo'      => 'h',
                    'footer_logo'      => 'f',
                    'primary_logo'     => 'p_main',
                    'secondary_logo'   => 'p_sec',
                    'favicon'          => 'fav',
                    'website_favicon'  => 'wfav',
                    default            => $key,
                };
                $filename = time() . "_{$prefix}_" . $data[$key]->getClientOriginalName();
                $upload   = $this->uploadFile($data[$key], 'site-settings', 'public', $filename);
                AppConfiguration::set($key, $upload['path'], $entityType, $entityId);

            } else {
                // String path from FileManager selection or existing value
                AppConfiguration::set($key, $this->normalizePath($data[$key]), $entityType, $entityId);
            }
        }

        if ($entityType === \App\Models\Partner\Partner::class) {
            \App\Models\User\User::where('partner_id', $entityId)->update(['is_refresh' => true]);
        }

        return $this->show($entityType, $entityId);
    }

    private function normalizePath($path): ?string
    {
        if (!$path || $path === 'null' || $path === 'undefined') {
            return null;
        }

        $path = ltrim((string)$path, '/');

        // If it's a full URL, try to extract the relative path if it points to our storage
        if (str_contains($path, '/storage/site-settings/')) {
            $parts = explode('/storage/site-settings/', $path);
            return 'site-settings/' . end($parts);
        }

        if (str_contains($path, '/storage/')) {
            $parts = explode('/storage/', $path);
            return end($parts);
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        if (!str_starts_with($path, 'site-settings/')) {
            return 'site-settings/' . $path;
        }

        return $path;
    }
}
