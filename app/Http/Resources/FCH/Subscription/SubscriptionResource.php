<?php

namespace App\Http\Resources\FCH\Subscription;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $partner = null;
        $borrower = null;

        if ($this->entity_type === \App\Models\Partner\Partner::class) {
            $partner = $this->partner;
        } elseif ($this->entity_type === \App\Models\Borrower\Borrower::class) {
            $borrower = $this->borrower;
            $partner = $borrower?->partner;
        }

        return [
            'id' => $this->id,
            'partner_id' => $partner->id ?? null,
            'borrower_id' => $borrower->id ?? null,
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
            'next_installment_date' => $this->enrollmentPhase?->next_installment_date?->format('Y-m-d')
                ?? $this->next_installment_date?->format('Y-m-d'),
            'next_platform_fee_date' => $this->platformFeePhase?->next_platform_fee_date?->format('Y-m-d'),
            'enrollment_completed_at' => $this->enrollmentPhase?->enrollment_completed_at?->toDateTimeString(),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'status' => $this->status,
            'partner_name' => $partner?->full_name,
            'company_name' => $partner?->company_name,
            'partner_email' => $partner?->email,
            'borrower_name' => $borrower ? ($borrower->first_name . ' ' . $borrower->last_name) : null,
            'borrower_email' => $borrower?->email,
            'created_at' => $this->created_at?->format('Y-m-d h:i A'),
            'updated_at' => $this->updated_at?->format('Y-m-d h:i A'),
        ];
    }
}
