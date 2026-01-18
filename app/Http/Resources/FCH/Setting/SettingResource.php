<?php

namespace App\Http\Resources\FCH\Setting;

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
            'partner_id' => $this->partner_id,
            'card_name_sandbox' => $this->card_name_sandbox,
            'card_no_sandbox' => $this->card_no_sandbox,
            'card_expiry_sandbox' => $this->card_expiry_sandbox,
            'card_cvv_sandbox' => $this->card_cvv_sandbox,
            'card_name_production' => $this->card_name_production,
            'card_no_production' => $this->card_no_production,
            'card_expiry_production' => $this->card_expiry_production,
            'card_cvv_production' => $this->card_cvv_production,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_mode' => $this->payment_mode,
            'stripe_key_sandbox' => $this->stripe_key_sandbox,
            'stripe_secret_sandbox' => $this->stripe_secret_sandbox,
            'stripe_key_production' => $this->stripe_key_production,
            'stripe_secret_production' => $this->stripe_secret_production,
            'payment_method' => $this->payment_method,
            'configuration' => $this->configuration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
