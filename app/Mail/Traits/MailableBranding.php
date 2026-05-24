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
            'logoUrl' => ($brandingConfig['secondary_logo'] && $brandingConfig['secondary_logo'] != null ) ? asset('storage/' . $brandingConfig['secondary_logo']) : asset('storage/' . $brandingConfig['primary_logo']),
        ];
    }

}
