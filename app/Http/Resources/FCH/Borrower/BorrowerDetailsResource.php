<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestSub = $this->latestSubscription;

        return [
            'id' => $this->id,
            'user_id' => $this->user?->id,
            // A new sign-in email awaiting confirmation from that address.
            'pending_email' => $this->user?->pending_email,
            'signup_id' => $this->signup_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'state' => $this->state,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'home_address' => $this->home_address,
            'home_phone' => $this->home_phone,
            'date_of_birth' => toDateOnly($this->date_of_birth, 'm-d-Y'),
            'last_4_ssn' => $this->last_4_ssn,
            // Full SSN, decrypted — shown and editable as-is on the client edit form.
            'ssn' => $this->ssn,

            // Business
            'business_name' => $this->business_name,
            'business_ein' => $this->business_ein,
            'business_phone' => $this->business_phone,
            'business_address' => $this->business_address,
            'business_country' => $this->business_country,
            'business_country_code' => $this->business_country_code,
            'business_city' => $this->business_city,
            'business_state' => $this->business_state,
            'business_postal_code' => $this->business_postal_code,
            'business_email' => $this->business_email,
            'business_website' => $this->business_website,
            'business_entity_type' => $this->business_entity_type,
            'business_entity_type_note' => $this->business_entity_type_note,
            'business_industry_type' => $this->business_industry_type,
            'business_industry_type_note' => $this->business_industry_type_note,
            'business_description' => $this->business_description,
            'business_logo' => $this->business_logo ? url($this->business_logo) : null,
            'business_registration_number' => $this->business_registration_number,
            'tax_identification_number' => $this->tax_identification_number,
            'duns_number' => $this->duns_number,
            'state_file_number' => $this->state_file_number,
            'business_start_date' => $this->business_start_date ? Carbon::parse($this->business_start_date)->format('m-d-Y') : null,

            // Enrollment details — mirrors PartnerDetailsResource::enrollment_details
            'enrollment_details' => $latestSub ? [
                'plan_id' => (int) $latestSub->subscription_plan_id,
                'enrollment_type' => $latestSub->enrollment_type,
                'payable_amount' => (float) $latestSub->payable_amount,
                'borrower_fee_amount' => (float) $latestSub->borrower_fee_amount,
                'borrower_fee_amount_formatted' => currencyFormat($latestSub->borrower_fee_amount),
                'payable_amount_formatted' => currencyFormat($latestSub->payable_amount),
                'total_subscription_amount' => (float) $latestSub->total_subscription_amount,
                'total_subscription_amount_formatted' => currencyFormat($latestSub->total_subscription_amount),
                'monthly_platform_fee' => (float) $latestSub->monthly_platform_fee,
                'monthly_platform_fee_formatted' => currencyFormat($latestSub->monthly_platform_fee),
                'enrollment_amount' => (float) $latestSub->payable_amount,
                'enrollment_amount_formatted' => currencyFormat($latestSub->payable_amount),
                'installment_applied' => $latestSub->installment_count > 0,
                'installment_down_payment' => $latestSub->down_payment !== null ? (float) $latestSub->down_payment : 0.0,
                'installment_down_payment_formatted' => currencyFormat($latestSub->down_payment),
                'installment_months' => $latestSub->installment_count ?? 0,
                'installment_monthly_amount' => $latestSub->subscription_amount_per_month !== null ? (float) $latestSub->subscription_amount_per_month : 0.0,
                'installment_monthly_amount_formatted' => currencyFormat($latestSub->subscription_amount_per_month),
                'installment_remaining_balance' => (float) ($latestSub->payable_amount - $latestSub->down_payment),
                'installment_remaining_balance_formatted' => currencyFormat($latestSub->payable_amount - $latestSub->down_payment),
                'subscription_start_date' => toDateOnly($latestSub->subscription_start_date),
                'subscription_end_date' => toDateOnly($latestSub->subscription_end_date), // paid through
                'status' => $latestSub->status,
            ] : null,

            // Status & Assignment
            'enrollment_type' => $this->latestSubscription?->enrollment_type,
            // Cast to int so the frontend can match it against numeric plan IDs in the dropdown.
            // Returns null for free-trial borrowers (no subscription yet) — dropdown remains enabled.
            'plan_id' => $this->latestSubscription ? (int) $this->latestSubscription->subscription_plan_id : null,
            'payment_amount' => $this->latestSubscription?->payable_amount,
            'total_subscription_amount' => $this->latestSubscription?->total_subscription_amount,

            // Financial
            'currency' => $this->currency,
            'years_in_business' => $this->business_start_date ? calculateTimeInBusiness($this->business_start_date) : null,
            'monthly_revenue' => formatComma($this->monthly_revenue),
            'annual_revenue' => formatComma($this->annual_revenue),
            'annual_personal_income' => formatComma($this->annual_personal_income),
            'avg_bank_balance' => formatComma($this->avg_bank_balance),
            'average_monthly_deposits' => formatComma($this->average_monthly_deposits),
            'monthly_deposit_count' => $this->monthly_deposit_count,
            'profit_margin' => $this->profit_margin,
            'current_business_debt' => formatComma($this->current_business_debt),
            'fico_score' => $this->fico_score,
            'paydex_score' => $this->paydex_score,
            'number_of_employees' => $this->number_of_employees,
            'nsf_count' => $this->nsf_count,
            'negative_days' => $this->negative_days,
            'funding_urgency' => $this->funding_urgency,
            'bank_accounts' => $this->bankAccounts ? $this->bankAccounts->map(fn($b) => [
                'id' => $b->id,
                'bank_name' => $b->bank_name,
                'account_open_date' => $b->account_open_date ? Carbon::parse($b->account_open_date)->format('Y-m-d') : null,
            ]) : [],

            'status' => $this->status,
            // Archived records still appear in search results, so the UI needs to
            // mark them. Presentation only - it never affects access or billing.
            'is_archived' => (bool) $this->is_archived,
            'archived_at' => toDateOnly($this->archived_at),
            'notes' => $this->notes,
            'assign_id' => $this->assign_id,
            'assign_name' => $this->assignedUser?->name,
            'partner_name' => $this->partner?->full_name,
            'partner_company' => $this->partner?->company_name,
            'partner_phone' => $this->partner?->phone_number,
            'partner_email' => $this->partner?->email,
            'partner_logo' => $this->partner?->partner_image ? url(encodeUrl($this->partner->partner_image)) : null,
            'partner_id' => $this->partner?->id,
            'partner_type' => $this->partner?->partner_type,
            'current_tier' => $this->current_tier,
            'current_tier_name' => $this->tier?->name,
            'additional_data' => $this->additional_data,
            'other_city' => $this->other_city,
            'is_free_trail' => $this->is_free_trail ? true : false,
            'free_trail_end_date' => toDateOnly($this->free_trail_end_date),
            'invoice' => $this->latestInvoice ? [
                'id' => $this->latestInvoice->id,
                'invoice_id' => $this->latestInvoice->invoice_id,
            ] : null,
            'has_sign' => (bool) $this->signedAgreement()->exists(),
        ];
    }
}
