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
        $user = auth()->user();
        $partnerId = null;

        if ($user) {
            if ($user->partner->is_host || $user->partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
                $partnerId = $user->partner_id;
            } else {
                $host = Partner::where('is_host', true)->first();
                $partnerId = $host ? $host->id : null;
            }
        }

        return Cache::remember("mail_branding_" . ($partnerId ?? 'default'), 3600, function () use ($partnerId) {
            $module = AppConfiguration::MODULE_BRANDING;
            $partner = $partnerId ? Partner::find($partnerId) : Partner::where('is_host', true)->first();

            // Site Name Resolution
            $siteName = AppConfiguration::get('site_name', $partner?->company_name ?? config('app.name'), $module, $partnerId);

            // Logo Resolution
            $logoPath = AppConfiguration::get('primary_logo', $partner?->company_logo, $module, $partnerId)
                ?? AppConfiguration::get('secondary_logo', $partner?->company_logo, $module, $partnerId);


            return [
                'siteName' => $siteName,
                'logoUrl' => $logoPath ? asset('storage/' . $logoPath) : null,
            ];
        });
    }

}
