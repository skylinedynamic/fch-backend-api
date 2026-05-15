<?php

namespace App\Services\Global\Notification\Mail;

use App\Models\Setting\EmailConfiguration;
use App\Models\Partner\Partner;

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
    public static function resolve(?int $partnerId): ?EmailConfiguration
    {
        // 1️⃣ Partner-level config
        if ($partnerId) {
            $config = EmailConfiguration::query()
                ->where('partner_id', $partnerId)
                ->where('is_active', 1)
                ->first();

            if ($config) return $config;
        }

        // 2️⃣ Fall back to the host partner's config
        $hostConfig = EmailConfiguration::query()
            ->whereHas('partner', fn($q) => $q->where('is_host', 1))
            ->where('is_active', 1)
            ->first();
            
        if ($hostConfig) return $hostConfig;

        // 3️⃣ Final fallback to system default (partner_id = null)
        return EmailConfiguration::query()
            ->whereNull('partner_id')
            ->where('is_active', 1)
            ->first();
    }
}
