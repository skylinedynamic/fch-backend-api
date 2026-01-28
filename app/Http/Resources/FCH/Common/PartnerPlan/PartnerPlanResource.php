<?php

namespace App\Http\Resources\FCH\Common\PartnerPlan;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_type' => $this->partner_type,
            'partner_type_slug' => $this->partner_type_slug,
            'enrollment_type' => $this->enrollment_type,
            'enrollment_type_slug' => $this->enrollment_type_slug,
            'payment_amount' => (float) $this->payment_amount,
            'installments_count' => $this->installments_count,
            'total_installments_count' => $this->total_installments_count,
            'total_amount' => (float) $this->total_amount,
            'platform_fee' => (float) $this->platform_fee,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'currency_rate' => (float) $this->currency_rate,
            'status' => (bool) $this->status,
        ];
    }
}
