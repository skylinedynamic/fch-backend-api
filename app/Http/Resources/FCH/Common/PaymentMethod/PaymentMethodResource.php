<?php

namespace App\Http\Resources\FCH\Common\PaymentMethod;

use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id ?? 1,
            'partner_id' => $this->partner_id ?? null,
            'payment_mode' => $this->payment_mode ?? PaymentProvider::MODE_SANDBOX,
            'stripe_key_sandbox' => $this->stripe_key_sandbox ?? null,
            'stripe_secret_sandbox' => $this->stripe_secret_sandbox ?? null,
            'stripe_key_production' => $this->stripe_key_production ?? null,
            'stripe_secret_production' => $this->stripe_secret_production ?? null,
            'payment_method' => $this->payment_method ?? PaymentProvider::STRIPE,
            'status' => $this->status ?? true,
            'partner_auto_charge_host_fee' => $this->partner_auto_charge_host_fee ?? 1,
            'configuration' => isset($this->configuration) ? (is_string($this->configuration) ? json_decode($this->configuration, true) : $this->configuration) : null,
            'created_at' => $this->created_at ?? now(),
        ];
    }
}
