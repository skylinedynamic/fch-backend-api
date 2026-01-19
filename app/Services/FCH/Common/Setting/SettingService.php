<?php

namespace App\Services\FCH\Common\Setting;

use App\Models\Setting\Setting;

class SettingService
{
    /**
     * Update or Create Settings for a partner.
     *
     * @param array $data
     * @return Setting
     */
    public function updateOrCreate(array $data): Setting
    {
        $setting = Setting::first();

        if ($setting) {
            $setting->update($data);
            return $setting;
        }

        $data['status'] = true;
        return Setting::create($data);
    }

    /**
     * Get Settings for a partner.
     *
     * @return Setting|null
     */
    public function getSettings(): ?Setting
    {
        return Setting::where('status', true)->first();
    }
}
