<?php

namespace App\Http\Resources\FCH\Common\Setting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_mode' => $this->payment_mode,
            'stripe_key_sandbox' => $this->stripe_key_sandbox,
            'stripe_secret_sandbox' => $this->stripe_secret_sandbox,
            'stripe_key_production' => $this->stripe_key_production,
            'stripe_secret_production' => $this->stripe_secret_production,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'configuration' => $this->configuration,
            'created_at' => $this->created_at,
        ];
    }
}
