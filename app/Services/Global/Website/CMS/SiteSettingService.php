<?php

namespace App\Services\Global\Website\CMS;

use App\Models\CMS\SiteSetting;
use App\Http\Resources\FCH\CMS\SiteSettingResource;

class SiteSettingService
{
    /**
     * Get the site settings.
     *
     * @return \Illuminate\Http\Resources\Json\JsonResource|null
     */
    public function getSiteSettings()
    {
        $setting = SiteSetting::first();
        
        if (!$setting) {
            return null;
        }

        return new SiteSettingResource($setting);
    }
}
