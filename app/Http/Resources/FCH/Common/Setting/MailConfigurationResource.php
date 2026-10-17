<?php

namespace App\Http\Resources\FCH\Common\Setting;

use App\Models\Setting\MailConfiguration;
use App\Services\FCH\Common\Setting\MailConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mail provider's settings as the settings screen sees them. Secrets are
 * only ever reported as set (masked) or not set.
 *
 * @mixin MailConfiguration
 */
class MailConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'provider' => $this->provider,
            'provider_label' => MailConfiguration::providers()[$this->provider]['label'] ?? $this->provider,
            'from_address' => $this->from_address,
            'bcc_emails' => $this->bcc_emails,
        ];

        foreach ($this->fields() as $key => $field) {
            $value = $this->credential($key);
            $data[$key] = !empty($field['secret'])
                ? ($value !== null ? MailConfigurationService::MASK : null)
                : $value;
        }

        $verified = $this->isVerified();

        return array_merge($data, [
            'is_default' => (bool) $this->is_default,
            'is_verified' => $verified,
            'verified_at' => $verified ? $this->verified_at?->toIso8601String() : null,
            'verified_to' => $verified ? $this->verified_to : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
