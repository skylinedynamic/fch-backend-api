<?php

namespace App\Http\Resources\FCH\PaymentMethod;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerPaymentProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id ?? null,
            'partner_id' => $this->partner_id,
            'payment_provider_id' => $this->payment_provider_id,
            'status' => (bool) ($this->status ?? false),
            'is_default' => (bool) ($this->is_default ?? false),
            'configuration' => $this->configuration ?: new \stdClass(),
        ];
    }
}
