<?php

namespace App\Http\Resources\FCH\Subscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;

class SubscriptionDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array for single subscription details.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPartner = $this->entity_type === Partner::class;
        $isBorrower = $this->entity_type === Borrower::class;

        $partner = $this->relationLoaded('partner') ? $this->getRelation('partner') : null;
        $borrower = $this->relationLoaded('borrower') ? $this->getRelation('borrower') : null;
        $user = $this->relationLoaded('user') ? $this->getRelation('user') : null;

        $subscriberName = null;
        $subscriberEmail = null;
        $companyName = null;
        $associatedPartnerName = null;

        if ($isPartner) {
            $subscriptionTitle = 'Partner Subscription';
            $subscriberName = $partner?->full_name ?? null;
            $subscriberEmail = $partner?->email ?? null;
            $companyName = $partner?->company_name ?? null;
        } elseif ($isBorrower) {
            $borrowerPartner = ($borrower && $borrower->relationLoaded('partner')) ? $borrower->getRelation('partner') : null;
            $subscriptionTitle = 'Borrower Subscription';
            $subscriberName = $borrower ? trim(($borrower->first_name ?? '') . ' ' . ($borrower->last_name ?? '')) : null;
            $subscriberEmail = $borrower?->email ?? null;
            $companyName = $borrowerPartner?->company_name ?? null;
            $associatedPartnerName = $borrowerPartner?->full_name ?? null;
        } else {
            $subscriptionTitle = 'Additional Seat Subscription';
            $subscriberName = $user?->name ?? $user?->full_name ?? null;
            $subscriberEmail = $user?->email ?? null;
            $companyName = null;
        }

        $isEnrollmentCompleted = !empty($this->enrollment_completed_at);

        // Next billing calculations according to entity type & enrollment state.
        // See Subscription::nextBilling() - shared with MySubscriptionService so
        // both pages agree on what's due next.
        $nextBilling = $this->resource->nextBilling();
        $nextBillingDate = toDateOnly($nextBilling['date']);
        $nextBillingType = $nextBilling['type'];
        $nextBillingAmount = currencyFormat($nextBilling['amount']);

        $totalSeatAmount = ($this->user_seats && $this->per_seat_amount)
            ? currencyFormat((float) $this->user_seats * (float) $this->per_seat_amount)
            : null;

        return [
            'id' => $this->id,
            'entity_type' => $this->entity_name,
            'subscription_title' => $subscriptionTitle,
            'status' => $this->status,

            // Subscriber Info
            'subscriber_name' => $subscriberName,
            'subscriber_email' => $subscriberEmail,
            'company_name' => $companyName,
            'associated_partner_name' => $associatedPartnerName,

            // Verified Database Dates
            'subscription_start_date' => toDateOnly($this->subscription_start_date),
            'subscription_end_date' => toDateOnly($this->subscription_end_date),
            'first_payment_date' => toDateOnly($this->first_payment_date),
            'created_at' => toTimezone($this->created_at),
            'updated_at' => toTimezone($this->updated_at),

            // Enrollment / Billing Status
            'is_enrollment_completed' => $isEnrollmentCompleted,
            'enrollment_completed_at' => toDateOnly($this->enrollment_completed_at),
            'next_billing_date' => $nextBillingDate,
            'next_billing_type' => $nextBillingType,
            'next_billing_amount' => $nextBillingAmount,

            // Financial & Plan Data
            'enrollment_type' => $this->enrollment_type,
            'partner_type' => $this->partner_type,
            'current_phase' => $this->current_phase,
            'payable_amount' => currencyFormat($this->payable_amount),
            'total_subscription_amount' => currencyFormat($this->total_subscription_amount),
            'subscription_amount_per_month' => currencyFormat($this->subscription_amount_per_month),
            'down_payment' => currencyFormat($this->down_payment),
            'monthly_platform_fee' => $isPartner ? currencyFormat($this->monthly_platform_fee) : null,
            'borrower_fee_amount' => !is_null($this->borrower_fee_amount) ? currencyFormat($this->borrower_fee_amount) : null,

            // Installment Details
            'installment_type' => $this->installment_type,
            'installment_count' => $this->installment_count,
            'installments_paid' => $this->installments_paid ?? 0,

            // Additional Seats Info (if applicable)
            'user_seats' => $this->user_seats,
            'per_seat_amount' => currencyFormat($this->per_seat_amount),
            'total_seat_amount' => $totalSeatAmount,
        ];
    }
}
