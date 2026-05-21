<?php

namespace App\Mail\Traits;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\Setting\EmailConfiguration;
use App\Services\Global\Notification\Mail\EmailConfigurationResolver;
use Illuminate\Support\Facades\Cache;

trait MailableBranding
{
    protected function getBrandingData(): array
    {
 
        $partnerId = getConfigPartner()->id;

        return Cache::remember("mail_branding_" . ($partnerId ?? 'default'), 3600, function () use ($partnerId) {
            $module = AppConfiguration::MODULE_BRANDING;
            $partner = $partnerId ? Partner::find($partnerId) : Partner::where('is_host', true)->first();

            // Site Name Resolution
            $siteName = AppConfiguration::get('site_name', $partner?->company_name ?? config('app.name'), $module, $partnerId);

            // Logo Resolution
            $logoPath = AppConfiguration::get('secondary_logo', $partner?->company_logo, $module, $partnerId)
                ?? AppConfiguration::get('primary_logo', $partner?->company_logo, $module, $partnerId);


            return [
                'siteName' => $siteName,
                'logoUrl' => $logoPath ? asset('storage/' . $logoPath) : null,
            ];
        });
    }

}
