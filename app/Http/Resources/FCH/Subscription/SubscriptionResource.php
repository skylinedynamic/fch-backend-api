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
            'monthly_platform_fee' => currencyFormat($this->monthly_platform_fee),
            'total_subscription_amount' => currencyFormat($this->total_subscription_amount),
            'subscription_amount_per_month' => currencyFormat($this->subscription_amount_per_month),
            'payable_amount' => currencyFormat($this->payable_amount),
            'total_paid_amount' => currencyFormat($this->total_paid_amount),
            'down_payment' => currencyFormat($this->down_payment),
            'per_seat_amount' => currencyFormat($this->per_seat_amount),
            'installment_type' => $this->installment_type,
            'installment_count' => $this->installment_count,
            'installments_paid' => $this->installments_paid,
            'current_phase' => $this->current_phase,
            'first_payment_date' => toTimezone($this->first_payment_date),
            'subscription_start_date' => toTimezone($this->subscription_start_date),
            'subscription_end_date' => toTimezone($this->subscription_end_date),
            'next_billing_date' => toTimezone($this->subscription_end_date),
            'enrollment_completed_at' => toTimezone($this->enrollment_completed_at, null, 'Y-m-d H:i:s'),
            'termination_date' => toTimezone($this->termination_date),
            'status' => $this->status,
            'partner_name' => $partner?->full_name,
            'company_name' => $partner?->company_name,
            'partner_email' => $partner?->email,
            'borrower_name' => $borrower ? ($borrower->first_name . ' ' . $borrower->last_name) : null,
            'borrower_email' => $borrower?->email,
            'created_at' => toTimezone($this->created_at),
            'updated_at' => toTimezone($this->updated_at),
        ];
    }
}
