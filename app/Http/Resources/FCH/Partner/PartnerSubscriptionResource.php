<?php

namespace App\Http\Resources\FCH\Partner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartnerSubscriptionResource extends JsonResource
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
            'partner_type' => $this->partner_type,
            'enrollment_type' => $this->enrollment_type,
            'monthly_platform_fee' => $this->monthly_platform_fee,
            'first_reg_amount' => $this->first_reg_amount,
            'first_reg_date' => $this->first_reg_date?->format('Y-m-d'),
            'installment_type' => $this->installment_type,
            'first_reg_payment' => $this->first_reg_payment,
            'first_reg_payment_2' => $this->first_reg_payment_2,
            'total_paid_amount' => $this->total_paid_amount,
            'subscription_start_date' => $this->subscription_start_date?->format('Y-m-d'),
            'subscription_end_date' => $this->subscription_end_date?->format('Y-m-d'),
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'status' => $this->status,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
