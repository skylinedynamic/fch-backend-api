<?php

namespace App\Http\Resources\FCH\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Subscription\SubscriptionPhase;

class BillingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->subscription;
        $partner = null;
        $borrower = null;

        if ($subscription) {
            if ($subscription->entity_type === Partner::class) {
                $partner = $subscription->partner;
            } elseif ($subscription->entity_type === Borrower::class) {
                $borrower = $subscription->borrower;
                $partner = $borrower?->partner;
            }
        }

        $isPlatformFee = $this->phase === SubscriptionPhase::PHASE_PLATFORM_FEE;

        $billingDate = $isPlatformFee
            ? $this->next_platform_fee_date
            : $this->next_installment_date;

        $billingAmount = $isPlatformFee
            ? ($subscription->monthly_platform_fee ?? 0)
            : ($subscription->payable_amount ?? 0);

        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'partner_id' => $partner->id ?? null,
            'borrower_id' => $borrower->id ?? null,
            'partner_name' => $partner?->full_name,
            'company_name' => $partner?->company_name,
            'partner_email' => $partner?->email,
            'borrower_name' => $borrower ? ($borrower->first_name . ' ' . $borrower->last_name) : null,
            'borrower_email' => $borrower?->email,
            'upcoming_billing_date' => $billingDate ? $billingDate->format('Y-m-d') : null,
            'upcoming_billing_amount' => $billingAmount,
            'upcoming_billing_type' => $isPlatformFee ? 'Platform Fee' : 'Installment',
            'enrollment_type' => $subscription?->enrollment_type,
            'status' => $subscription?->status ?? 'Active',
            'created_at' => $this->created_at?->format('Y-m-d'),
            'updated_at' => $this->updated_at?->format('Y-m-d'),
        ];
    }
}
