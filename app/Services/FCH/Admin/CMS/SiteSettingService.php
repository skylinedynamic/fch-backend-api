<?php

namespace App\Services\FCH\Admin\CMS;

use App\Models\CMS\SiteSetting;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiteSettingService
{
    use UploadsFiles;

    /**
     * Get or create site settings for a partner.
     */
    public function getSettings(int $partnerId): SiteSetting
    {
        $setting = SiteSetting::where('partner_id', $partnerId)->first();
        if (!$setting) {
            $setting = SiteSetting::create([
                'site_name' => 'Four Corner Funding',
                'partner_id' => $partnerId,
                'created_by' => $partnerId,
                'updated_by' => $partnerId,
            ]);
        }
        return $setting;
    }

    /**
     * Update site settings.
     */
    public function updateSettings(int $partnerId, array $data): SiteSetting
    {
        DB::beginTransaction();
        try {
            $setting = SiteSetting::where('partner_id', $partnerId)->first();
            if (!$setting) {
                $setting = new SiteSetting();
                $setting->partner_id = $partnerId;
                $setting->created_by = $partnerId;
            }

            $setting->updated_by = $partnerId;

            // Handle social links
            if (isset($data['social_links'])) {
                $links = is_array($data['social_links']) 
                    ? $data['social_links'] 
                    : json_decode($data['social_links'], true);
                
                $data['social_links'] = array_values(array_filter($links ?? [], function($link) {
                    return !empty($link['platform']) || !empty($link['url']);
                }));
            }

            // Handle Header Logo
            if (isset($data['header_logo']) && $data['header_logo'] instanceof UploadedFile) {
                if ($setting->getRawOriginal('header_logo')) {
                    $this->deleteFile($setting->getRawOriginal('header_logo'), 'public');
                }
                $filename = time() . '_h_' . $data['header_logo']->getClientOriginalName();
                $upload = $this->uploadFile($data['header_logo'], 'site-settings', 'public', $filename);
                $data['header_logo'] = $upload['path'];
            } elseif (isset($data['header_logo']) && is_string($data['header_logo'])) {
                $data['header_logo'] = $this->normalizePath($data['header_logo']);
            }

            // Handle Footer Logo
            if (isset($data['footer_logo']) && $data['footer_logo'] instanceof UploadedFile) {
                if ($setting->getRawOriginal('footer_logo')) {
                    $this->deleteFile($setting->getRawOriginal('footer_logo'), 'public');
                }
                $filename = time() . '_f_' . $data['footer_logo']->getClientOriginalName();
                $upload = $this->uploadFile($data['footer_logo'], 'site-settings', 'public', $filename);
                $data['footer_logo'] = $upload['path'];
            } elseif (isset($data['footer_logo']) && is_string($data['footer_logo'])) {
                $data['footer_logo'] = $this->normalizePath($data['footer_logo']);
            }

            // Handle Partner Logo
            if (isset($data['partner_logo']) && $data['partner_logo'] instanceof UploadedFile) {
                if ($setting->getRawOriginal('partner_logo')) {
                    $this->deleteFile($setting->getRawOriginal('partner_logo'), 'public');
                }
                $filename = time() . '_p_' . $data['partner_logo']->getClientOriginalName();
                $upload = $this->uploadFile($data['partner_logo'], 'site-settings', 'public', $filename);
                $data['partner_logo'] = $upload['path'];
            } elseif (isset($data['partner_logo']) && is_string($data['partner_logo'])) {
                $data['partner_logo'] = $this->normalizePath($data['partner_logo']);
            }

            // Handle Favicon
            if (isset($data['favicon']) && $data['favicon'] instanceof UploadedFile) {
                if ($setting->getRawOriginal('favicon')) {
                    $this->deleteFile($setting->getRawOriginal('favicon'), 'public');
                }
                $filename = time() . '_fav_' . $data['favicon']->getClientOriginalName();
                $upload = $this->uploadFile($data['favicon'], 'site-settings', 'public', $filename);
                $data['favicon'] = $upload['path'];
            } elseif (isset($data['favicon']) && is_string($data['favicon'])) {
                $data['favicon'] = $this->normalizePath($data['favicon']);
            }

            $setting->fill($data);
            $setting->save();

            DB::commit();
            return $setting->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Site settings update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Normalize file manager path.
     */
    private function normalizePath(string $path): string
    {
        $path = ltrim($path, '/');
        if (!str_starts_with($path, 'site-settings/')) {
            return 'site-settings/' . $path;
        }
        return $path;
    }
}
