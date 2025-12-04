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
            'payment_type' => $this->payment_type,
            'partner_url' => $this->partner_url,
            'billing_term' => $this->billing_term,
            'subscription_term_months' => $this->subscription_term_months,
            'first_reg_date' => $this->first_reg_date?->format('Y-m-d'),
            'first_reg_amount' => $this->first_reg_amount,
            'first_payment_amount' => $this->first_payment_amount,
            'first_reg_amount_2' => $this->first_reg_amount_2,
            'recurring_amount' => $this->recurring_amount,
            'total_amount' => $this->total_amount,
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
