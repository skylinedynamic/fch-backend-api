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
        'copyright_text',
        'primary_color',
        'secondary_color',
    ];

    private const IMAGE_KEYS = [
        'header_logo',
        'footer_logo',
        'partner_logo',
        'borrower_logo',
        'partner_favicon',
        'borrower_favicon',
    ];

    /**
     * Get general branding configurations.
     */
    public function show(?string $entityType = null, ?int $entityId = null): array
    {
        $entityType = \App\Models\Partner\Partner::class;
        $entityId   = $entityId ?? auth()->user()->partner_id;

        // 1. Get configurations for the specific entity (Partner)
        $rows = AppConfiguration::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get()
            ->keyBy('key');

        // 2. Get configurations for the HOST if current is not host
        $hostId = \App\Models\Partner\Partner::where('is_host', 1)->value('id');
        $hostRows = [];
        if ($hostId && $hostId != $entityId) {
            $hostRows = AppConfiguration::where('entity_type', $entityType)
                ->where('entity_id', $hostId)
                ->get()
                ->keyBy('key');
        }

        $data = [];
        foreach (self::TEXT_KEYS as $key) {
            if ($rows->has($key) && !empty($rows[$key]->value)) {
                $data[$key] = $rows[$key]->value;
            } elseif (isset($hostRows[$key]) && !empty($hostRows[$key]->value)) {
                $data[$key] = $hostRows[$key]->value;
            } else {
                $data[$key] = null;
            }
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

        return $data;
    }

    /**
     * Update general branding configurations.
     */
    public function update(array $data, ?string $entityType = null, ?int $entityId = null)
    {
        $entityType = \App\Models\Partner\Partner::class;
        $entityId   = $entityId ?? auth()->user()->partner_id;

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
                    'partner_logo'     => 'p',
                    'borrower_logo'    => 'b',
                    'partner_favicon'  => 'pfav',
                    'borrower_favicon' => 'bfav',
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

        return $this->show($entityType, $entityId);
    }

    private function normalizePath($path): ?string
    {
        if (!$path || $path === 'null' || $path === 'undefined') {
            return null;
        }

        $path = ltrim((string)$path, '/');
        if (!str_starts_with($path, 'site-settings/') && !str_starts_with($path, 'http')) {
            return 'site-settings/' . $path;
        }
        return $path;
    }
}
