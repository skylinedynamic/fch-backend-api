<?php

namespace App\Http\Resources\FCH\Common\Setting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'partner_id'                  => $this->partner_id,
            'enable_custom_configuration' => (bool) $this->enable_custom_configuration,
            'site_name'                   => $this->site_name,
            'email_provider'              => $this->email_provider,

            // SMTP
            'smtp_driver'                 => $this->smtp_driver,
            'smtp_host'                   => $this->smtp_host,
            'smtp_port'                   => $this->smtp_port,
            'smtp_encryption'             => $this->smtp_encryption,
            'smtp_username'               => $this->smtp_username,
            'smtp_password'               => $this->when(
                                                $this->smtp_password,
                                                '••••••••'
                                            ),

            // AWS SES
            'aws_access_key'              => $this->aws_access_key,
            'aws_secret_key'              => $this->when(
                                                $this->aws_secret_key,
                                                '••••••••'
                                            ),
            'aws_region'                  => $this->aws_region,

            // Common
            'from_address'                => $this->from_address,
            'from_name'                   => $this->from_name,
            'bcc_emails'                  => $this->bcc_emails,
            'is_active'                   => (bool) $this->is_active,

            'created_at'      => $this->created_at?->toISOString(),
            'updated_at'      => $this->updated_at?->toISOString(),
        ];
    }
}