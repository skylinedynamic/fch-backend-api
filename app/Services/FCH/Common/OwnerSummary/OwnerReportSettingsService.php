<?php

namespace App\Services\FCH\Common\OwnerSummary;

use App\Models\OwnerSummary\OwnerReportSetting;

class OwnerReportSettingsService
{
    /** The saved settings, or the defaults (off, nobody) when none were saved. */
    public function forPartner(int $partnerId): OwnerReportSetting
    {
        return OwnerReportSetting::firstOrNew(['partner_id' => $partnerId]);
    }

    /** @param list<string> $recipients */
    public function save(int $partnerId, bool $enabled, array $recipients): OwnerReportSetting
    {
        return OwnerReportSetting::updateOrCreate(
            ['partner_id' => $partnerId],
            ['is_enabled' => $enabled, 'recipients' => array_values($recipients)]
        );
    }
}
