<?php

namespace App\Mail\Traits;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\Setting\EmailConfiguration;
use Illuminate\Support\Facades\Cache;

trait MailableBranding
{
    protected function getBrandingData(): array
    {
 
        $partnerId = getConfigPartner()->id;

        $module = AppConfiguration::MODULE_BRANDING;
        $partner = $partnerId ? Partner::find($partnerId) : Partner::where('is_host', true)->first();
            
        $brandingConfig = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', $module)
            ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
            ->pluck('value', 'key')
            ->toArray();

    
        return [
            'siteName' => $brandingConfig['site_name'] ?? $partner->company_name ,
            'logoUrl' => (isset($brandingConfig['secondary_logo']) && $brandingConfig['secondary_logo'] != null ) ? asset('storage/' . $brandingConfig['secondary_logo']) : (isset($brandingConfig['primary_logo']) ? asset('storage/' . $brandingConfig['primary_logo']) : null),
        ];
    }

    protected function getHostBrandingData(): array
    {
        $hostPartner = Partner::where('is_host', true)->first();
        $partnerId = $hostPartner ? $hostPartner->id : null;

        if (!$hostPartner) {
            \Illuminate\Support\Facades\Log::warning('MailableBranding (getHostBrandingData): No host partner found (is_host = true).');
        } else {
            \Illuminate\Support\Facades\Log::info("MailableBranding (getHostBrandingData): Fetched host partner (ID: {$partnerId}).");
        }

        $module = AppConfiguration::MODULE_BRANDING;
            
        $brandingConfig = AppConfiguration::where('partner_id', $partnerId)
            ->where('module', $module)
            ->whereIn('key', ['site_name', 'primary_logo', 'secondary_logo'])
            ->pluck('value', 'key')
            ->toArray();

        $siteName = $brandingConfig['site_name'] ?? ($hostPartner ? $hostPartner->company_name : config('app.name'));
        \Illuminate\Support\Facades\Log::info("MailableBranding: Using siteName '{$siteName}'");

        return [
            'siteName' => $siteName,
            'logoUrl' => (isset($brandingConfig['secondary_logo']) && $brandingConfig['secondary_logo'] != null ) ? asset('storage/' . $brandingConfig['secondary_logo']) : (isset($brandingConfig['primary_logo']) ? asset('storage/' . $brandingConfig['primary_logo']) : null),
        ];
    }

}
