<?php

namespace App\Services\FCH\Admin\Setting;

use App\Models\Setting\Setting;

class SettingService
{
    /**
     * Update or Create Settings for a partner.
     *
     * @param array $data
     * @param int $partnerId
     * @return Setting
     */
    public function updateOrCreate(array $data, int $partnerId): Setting
    {
        return Setting::updateOrCreate(
            ['partner_id' => $partnerId],
            $data
        );
    }

    /**
     * Get Settings for a partner.
     *
     * @param int $partnerId
     * @return Setting|null
     */
    public function getSettings(int $partnerId): ?Setting
    {
        return Setting::where('partner_id', $partnerId)->first();
    }
}
