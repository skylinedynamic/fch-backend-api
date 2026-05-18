<?php

namespace App\Services\Global\Notification\Mail;

use App\Models\Partner\Partner;
use App\Services\FCH\Common\Setting\EmailConfigurationService;

class EmailConfigurationResolver
{
    /**
     * Resolve the correct EmailConfiguration for a given partner.
     *
     * Priority:
     *  1. Partner-specific config (partner_id), is_active = 1
     *  2. Host partner config (is_host = 1), is_active = 1
     *  3. System default (partner_id = null), is_active = 1
     */
    public static function resolve(?int $partnerId): ?object
    {
        $service = app(EmailConfigurationService::class);

        // 1️⃣ Partner-level config
        if ($partnerId) {
            $config = $service->getActiveConfig($partnerId);
            if ($config) return $config;
        }

        // 2️⃣ Fall back to the host partner's config
        $hostPartner = Partner::where('is_host', 1)->first();
        if ($hostPartner) {
            $hostConfig = $service->getActiveConfig($hostPartner->id);
            if ($hostConfig) return $hostConfig;
        }

        // 3️⃣ Final fallback to system default (partner_id = null)
        return $service->getActiveConfig(null);
    }
}
