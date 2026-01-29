<?php

namespace App\Http\Resources\FCH\Subscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
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
            'borrower_id' => $this->borrower_id,
            'partner_type' => $this->partner_type,
            'enrollment_type' => $this->enrollment_type,
            'monthly_platform_fee' => $this->monthly_platform_fee,
            'total_subscription_amount' => $this->total_subscription_amount,
            'payable_amount' => $this->payable_amount,
            'installment_type' => $this->installment_type,
            'first_payment_date' => $this->first_payment_date?->format('Y-m-d'),
            'platform_fee_start_date' => $this->platform_fee_start_date?->format('Y-m-d'),
            'total_paid_amount' => $this->total_paid_amount,
            'subscription_start_date' => $this->subscription_start_date?->format('Y-m-d'),
            'subscription_end_date' => $this->subscription_end_date?->format('Y-m-d'),
            'next_installment_date' => $this->next_installment_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'status' => $this->status,
            'partner_name' => $this->partner?->full_name,
            'company_name' => $this->partner?->company_name,
            'partner_email' => $this->partner?->email,
            'borrower_name' => $this->borrower ? ($this->borrower->first_name . ' ' . $this->borrower->last_name) : null,
            'borrower_email' => $this->borrower?->email,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
