<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerPersonalDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Returns all fields used by the Client Info tab in the frontend.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestSub = $this->latestSubscription;

        return [
            // Identity
            'id'            => $this->id,
            'user_id'       => $this->user?->id,
            'signup_id'     => $this->signup_id,
            'first_name'    => $this->first_name,
            'middle_name'    => $this->middle_name,
            'last_name'     => $this->last_name,
            'full_name'     => $this->full_name,
            'email'         => $this->email,
            'phone_number'  => $this->phone_number,

            // Home Address
            'country'       => $this->country,
            'country_code'  => $this->country_code,
            'state'         => $this->state,
            'city'          => $this->city,
            'other_city'    => $this->other_city,
            'postal_code'   => $this->postal_code,
            'home_address'  => $this->home_address,
            'home_phone'    => $this->home_phone,
            'date_of_birth' => $this->date_of_birth ? Carbon::parse($this->date_of_birth)->format('m-d-Y') : null,
            'last_4_ssn'    => $this->last_4_ssn,

            // Business Info
            'business_name'                 => $this->business_name,
            'business_ein'                  => $this->business_ein,
            'business_phone'                => $this->business_phone,
            'business_address'              => $this->business_address,
            'business_country'              => $this->business_country,
            'business_country_code'         => $this->business_country_code,
            'business_city'                 => $this->business_city,
            'business_state'                => $this->business_state,
            'business_other_city'           => $this->business_other_city ?? null,
            'business_postal_code'          => $this->business_postal_code,
            'business_email'                => $this->business_email,
            'business_website'              => $this->business_website,
            'business_entity_type'          => $this->business_entity_type,
            'business_industry_type'        => $this->business_industry_type,
            'business_description'          => $this->business_description,
            'business_logo'                 => $this->business_logo ? url($this->business_logo) : null,
            'business_registration_number'  => $this->business_registration_number,
            'tax_identification_number'     => $this->tax_identification_number,
            'duns_number'                   => $this->duns_number,
            'state_file_number'             => $this->state_file_number,
            'business_start_date'           => $this->business_start_date ? Carbon::parse($this->business_start_date)->format('m-d-Y') : null,

            // Enrollment details (from latest subscription)
            'enrollment_details' => $latestSub ? [
                'plan_id'                               => (int) $latestSub->subscription_plan_id,
                'enrollment_type'                       => $latestSub->enrollment_type,
                'payable_amount'                        => (float) $latestSub->payable_amount,
                'payable_amount_formatted'              => currencyFormat($latestSub->payable_amount),
                'total_subscription_amount'             => (float) $latestSub->total_subscription_amount,
                'total_subscription_amount_formatted'   => currencyFormat($latestSub->total_subscription_amount),
                'monthly_platform_fee'                  => (float) $latestSub->monthly_platform_fee,
                'monthly_platform_fee_formatted'        => currencyFormat($latestSub->monthly_platform_fee),
                'enrollment_amount'                     => (float) $latestSub->payable_amount,
                'enrollment_amount_formatted'           => currencyFormat($latestSub->payable_amount),
                'installment_applied'                   => $latestSub->installment_count > 0,
                'installment_down_payment'              => $latestSub->down_payment !== null ? (float) $latestSub->down_payment : 0.0,
                'installment_down_payment_formatted'    => currencyFormat($latestSub->down_payment),
                'installment_months'                    => $latestSub->installment_count ?? 0,
                'installment_monthly_amount'            => $latestSub->subscription_amount_per_month !== null ? (float) $latestSub->subscription_amount_per_month : 0.0,
                'installment_monthly_amount_formatted'  => currencyFormat($latestSub->subscription_amount_per_month),
                'installment_remaining_balance'         => (float) ($latestSub->payable_amount - $latestSub->down_payment),
                'installment_remaining_balance_formatted' => currencyFormat($latestSub->payable_amount - $latestSub->down_payment),
                'subscription_start_date'               => toDateOnly($latestSub->subscription_start_date),
                'subscription_end_date'                 => toDateOnly($latestSub->subscription_end_date), // paid through
                'status'                                => $latestSub->status,
            ] : null,

            // Status & Assignment
            'status'        => $this->status,
            'notes'         => $this->notes,
            'assign_id'     => $this->assign_id,
            'assign_name'   => $this->assignedUser?->name,
            'current_tier'  => $this->current_tier,

            // Partner Info
            'partner_name'    => $this->partner?->full_name,
            'partner_company' => $this->partner?->company_name,
            'partner_phone'   => $this->partner?->phone_number,
            'partner_email'   => $this->partner?->email,
            'partner_logo'    => $this->partner?->partner_image ? url(encodeUrl($this->partner->partner_image)) : null,
            'partner_id'      => $this->partner?->id,
            'partner_type'    => $this->partner?->partner_type,

            // Free Trial
            'is_free_trail'      => $this->is_free_trail ? true : false,
            'free_trail_end_date'=> $this->free_trail_end_date ? Carbon::parse($this->free_trail_end_date)->format('Y-m-d') : null,

            // Agreement
            'has_sign' => (bool) $this->signedAgreement()->exists(),
        ];
    }
}
