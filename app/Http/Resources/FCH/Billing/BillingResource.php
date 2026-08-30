<?php

namespace App\Http\Resources\FCH\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Subscription\Subscription;

class BillingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $partner = null;
        $borrower = null;

        if ($this->entity_type === Partner::class) {
            $partner = $this->partner;
        } elseif ($this->entity_type === Borrower::class) {
            $borrower = $this->borrower;
            $partner = $borrower?->partner;
        }

        // Amount mapping based on current_phase:
        // - PHASE_INSTALLMENT: subscription_amount_per_month
        // - PHASE_PLATFORM_FEE: monthly_platform_fee
        // - PHASE_USER_SEAT: per_seat_amount
        // - PHASE_COMPLETED: nothing further is owed (borrower finished enrollment)
        $billingAmount = match ($this->current_phase) {
            Subscription::PHASE_INSTALLMENT => $this->subscription_amount_per_month ?? 0,
            Subscription::PHASE_PLATFORM_FEE => $this->monthly_platform_fee ?? 0,
            Subscription::PHASE_USER_SEAT => $this->per_seat_amount ?? 0,
            Subscription::PHASE_COMPLETED => 0,
            default => $this->subscription_amount_per_month ?? $this->payable_amount ?? $this->down_payment ?? 0,
        };

        // Human readable billing type for UI table
        $billingType = match ($this->current_phase) {
            Subscription::PHASE_PLATFORM_FEE => 'Platform Fee',
            Subscription::PHASE_USER_SEAT => 'User Seat',
            Subscription::PHASE_INSTALLMENT => 'Installment',
            Subscription::PHASE_COMPLETED => 'Completed',
            default => ucfirst(str_replace('_', ' ', $this->current_phase ?? 'Installment')),
        };

        return [
            'id' => $this->id,
            'subscription_id' => $this->id,
            'partner_id' => $partner?->id,
            'borrower_id' => $borrower?->id,
            'partner_name' => $partner?->full_name,
            'company_name' => $partner?->company_name,
            'partner_email' => $partner?->email,
            'borrower_name' => $borrower ? trim($borrower->first_name . ' ' . $borrower->last_name) : null,
            'borrower_email' => $borrower?->email,
            'upcoming_billing_date' => toTimezone($this->subscription_end_date, null, 'Y-m-d'),
            'upcoming_billing_amount' => (float) $billingAmount,
            'upcoming_billing_type' => $billingType,
            'current_phase' => $this->current_phase,
            'enrollment_type' => $this->enrollment_type,
            'status' => $this->status ?? Subscription::STATUS_ACTIVE,
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d'),
        ];
    }
}
