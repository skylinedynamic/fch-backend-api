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
        return [
            'id'            => $this->id,
            'user_id'       => $this->user?->id,
            'signup_id'     => $this->signup_id,
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'full_name'     => $this->full_name,
            'email'         => $this->email,
            'phone_number'  => $this->phone_number,
            'country'       => $this->country,
            'country_code'  => $this->country_code,
            'state'         => $this->state,
            'city'          => $this->city,
            'postal_code'   => $this->postal_code,
            'home_address'  => $this->home_address,
            'home_phone'    => $this->home_phone,
            'date_of_birth' => $this->date_of_birth,
            'last_4_ssn'    => $this->last_4_ssn,

            // Business
            'business_name'             => $this->business_name,
            'business_ein'              => $this->business_ein,
            'business_phone'            => $this->business_phone,
            'business_address'          => $this->business_address,
            'business_city'             => $this->business_city,
            'business_state'            => $this->business_state,
            'business_postal_code'      => $this->business_postal_code,
            'business_email'            => $this->business_email,
            'business_website'          => $this->business_website,
            'business_entity_type'      => $this->business_entity_type,
            'business_industry_type'    => $this->business_industry_type,
            'business_description'      => $this->business_description,
            'business_logo'             => $this->business_logo ? url($this->business_logo) : null,
            'business_registration_number' => $this->business_registration_number,
            'tax_identification_number' => $this->tax_identification_number,
            'duns_number'               => $this->duns_number,
            'state_file_number'         => $this->state_file_number,
            'business_start_date'       => Carbon::parse($this->business_start_date)->format('m-d-Y') ?? null,
            'enrollment_type'           => $this->latestSubscription?->enrollment_type,
            'payment_amount'            => $this->latestSubscription?->payable_amount,
            'total_subscription_amount' => $this->latestSubscription?->total_subscription_amount,

            // Financial
            'currency'                  => $this->currency,
            'years_in_business'         => $this->years_in_business,
            'monthly_revenue'           => formatComma($this->monthly_revenue),
            'annual_revenue'            => formatComma($this->annual_revenue),
            'avg_bank_balance'          => formatComma($this->avg_bank_balance),
            'profit_margin'             => $this->profit_margin,
            'current_business_debt'     => formatComma($this->current_business_debt),
            'fico_score'                => $this->fico_score,
            'paydex_score'              => $this->paydex_score,
            'number_of_employees'       => $this->number_of_employees,
            'nsf_days'                  => $this->nsf_days,
            'funding_urgency'           => $this->funding_urgency,

            'status'        => $this->status,
            'notes'         => $this->notes,
            'assign_id'     => $this->assign_id,
            'assign_name'   => $this->assignedUser?->name,
            'partner_name'  => $this->partner?->full_name,
            'partner_id'    => $this->partner?->id,
            'current_tier'  => $this->current_tier,
            'current_tier_name' => $this->tier?->name,
            'additional_data' => $this->additional_data,
            'other_city'    => $this->other_city,
            'invoice' => $this->latestInvoice ? [
                'id' => $this->latestInvoice->id,
                'invoice_id' => $this->latestInvoice->invoice_id,
            ] : null,
            'has_sign' => (bool) $this->signedAgreement()->exists(),
        ];
    }
}
