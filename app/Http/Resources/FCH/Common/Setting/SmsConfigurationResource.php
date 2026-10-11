<?php

namespace App\Http\Resources\FCH\Common\Setting;

use App\Services\FCH\Common\Setting\SmsConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A partner's SMS configuration as the settings screen sees it. Secrets are
 * only ever reported as set (masked) or not set.
 */
class SmsConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'country' => $this->country,
            'provider' => $this->provider,
            'is_enabled' => (bool) $this->is_enabled,
            'from_number' => $this->from_number,
            'account_sid' => $this->credential('account_sid'),
            'auth_token' => $this->credential('auth_token') ? SmsConfigurationService::MASK : null,
            'messaging_service_sid' => $this->credential('messaging_service_sid'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
