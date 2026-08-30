<?php

namespace App\Http\Resources\FCH\Common\Setting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id ?? null,
            'partner_id'                  => $this->partner_id ?? null,
            'enable_custom_configuration' => isset($this->enable_custom_configuration) ? filter_var($this->enable_custom_configuration, FILTER_VALIDATE_BOOLEAN) : false,
            'site_name'                   => $this->site_name ?? null,
            'email_provider'              => $this->email_provider ?? null,

            // SMTP
            'smtp_driver'                 => $this->smtp_driver ?? null,
            'smtp_host'                   => $this->smtp_host ?? null,
            'smtp_port'                   => $this->smtp_port ?? null,
            'smtp_encryption'             => $this->smtp_encryption ?? null,
            'smtp_username'               => $this->smtp_username ?? null,
            'smtp_password'               => !empty($this->smtp_password) ? '••••••••' : null,

            // AWS SES
            'aws_access_key'              => $this->aws_access_key ?? null,
            'aws_secret_key'              => !empty($this->aws_secret_key) ? '••••••••' : null,
            'aws_region'                  => $this->aws_region ?? null,

            // Common
            'from_address'                => $this->from_address ?? null,
            // 'from_name'                   => $this->from_name ?? null,
            'bcc_emails'                  => $this->bcc_emails ?? null,
            'is_active'                   => isset($this->is_active) ? filter_var($this->is_active, FILTER_VALIDATE_BOOLEAN) : false,
        ];
    }
}