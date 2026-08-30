<?php

namespace App\Http\Resources\FCH\Borrower;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerProfileResource extends JsonResource
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
            'business_entity_type_note' => $this->business_entity_type_note,
            'business_industry_type'    => $this->business_industry_type,
            'business_industry_type_note'=> $this->business_industry_type_note,
            'business_description'      => $this->business_description,
            'business_logo'             => $this->business_logo ? url($this->business_logo) : null,
            'business_registration_number' => $this->business_registration_number,
            'tax_identification_number' => $this->tax_identification_number,
            'duns_number'               => $this->duns_number,
            'state_file_number'         => $this->state_file_number,
            'business_start_date'       => $this->business_start_date,

            // Financial
            'currency'                  => $this->currency,
            'years_in_business'         => $this->business_start_date ? calculateTimeInBusiness($this->business_start_date) : null,
            'monthly_revenue'           => $this->monthly_revenue,
            'annual_revenue'            => $this->annual_revenue,
            'avg_bank_balance'          => $this->avg_bank_balance,
            'average_monthly_deposits'  => $this->average_monthly_deposits,
            'monthly_deposit_count'     => $this->monthly_deposit_count,
            'profit_margin'             => $this->profit_margin,
            'current_business_debt'     => $this->current_business_debt,
            'fico_score'                => $this->fico_score,
            'paydex_score'              => $this->paydex_score,
            'number_of_employees'       => $this->number_of_employees,
            'nsf_count'                 => $this->nsf_count,
            'negative_days'             => $this->negative_days,
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
            'credit_profile' => $this->creditProfile ? [
                'metrics' => [
                    'fico_score' => $this->creditProfile->fico_score,
                    'paydex_score' => $this->creditProfile->paydex_score,
                    'experian_intelliscore' => $this->creditProfile->experian_intelliscore,
                    'equifax_score' => $this->creditProfile->equifax_score,
                    'active_tradelines' => $this->creditProfile->active_tradelines,
                    'total_credit_limit' => $this->creditProfile->total_credit_limit,
                    'credit_utilization' => $this->creditProfile->credit_utilization,
                    'recent_inquiries' => $this->creditProfile->recent_inquiries,
                    'delinquent_accounts' => $this->creditProfile->delinquent_accounts,
                    'public_records' => $this->creditProfile->public_records,
                    'collections' => $this->creditProfile->collections,
                    'delinquencies_30_60_90' => $this->creditProfile->delinquencies_30_60_90,
                    'summary' => $this->creditProfile->summary,
                    'all_accounts_analysis' => array_merge([
                        'total_accounts' => $this->creditProfile->all_accounts_total,
                        'open_accounts' => $this->creditProfile->all_accounts_open,
                        'closed_accounts' => $this->creditProfile->all_accounts_closed,
                        'newest_open_date' => $this->creditProfile->all_accounts_newest_open_date,
                        'oldest_open_date' => $this->creditProfile->all_accounts_oldest_open_date,
                    ], $this->creditProfile->all_accounts_analysis ?? []),
                    'open_accounts_analysis' => array_merge([
                        'monthly_payments' => $this->creditProfile->open_accounts_monthly_payments,
                        'balance' => $this->creditProfile->open_accounts_balance,
                        'credit_limit' => $this->creditProfile->open_accounts_credit_limit,
                        'available_credit' => $this->creditProfile->open_accounts_available_credit,
                        'utilization_ratio' => $this->creditProfile->open_accounts_utilization_ratio,
                    ], $this->creditProfile->open_accounts_analysis ?? [])
                ],
                'personal_info' => [
                    'name' => $this->first_name . ' ' . $this->last_name,
                    'dob' => $this->date_of_birth,
                    'ssn' => $this->last_4_ssn,
                    'email' => $this->email,
                    'phone' => $this->phone_number,
                ],
                'addresses' => $this->creditProfile->addresses->map(function ($addr) {
                    return [
                        'type' => $addr->type,
                        'street' => $addr->street,
                        'city' => $addr->city,
                        'state' => $addr->state,
                        'zip' => $addr->zip,
                        'date_reported' => $addr->date_reported,
                    ];
                })->toArray(),
                'trade_accounts' => $this->creditProfile->trades->map(function ($trade) {
                    return [
                        'company' => $trade->company,
                        'date_reported' => $trade->date_reported,
                        'status' => $trade->status,
                        'rating' => $trade->rating,
                        'account_number' => $trade->account_number,
                        'account_type' => $trade->account_type,
                        'portfolio_type' => $trade->portfolio_type,
                        'remarks' => $trade->remarks,
                        'payment_history_start_date' => $trade->payment_history_start_date,
                        'payment_frequency' => $trade->payment_frequency,
                        'responsibility' => $trade->responsibility,
                        'opened_date' => $trade->opened_date,
                        'terms' => $trade->terms,
                        'last_payment_date' => $trade->last_payment_date,
                        'balance' => $trade->balance,
                        'high_credit' => $trade->high_credit,
                        'limit' => $trade->limit,
                        'monthly_payment' => $trade->monthly_payment,
                        'actual_payment' => $trade->actual_payment,
                        'past_due' => $trade->past_due,
                        'months_reviewed' => $trade->months_reviewed,
                        'delinquencies' => $trade->delinquencies_30_60_90,
                        'max_delinquency_date' => $trade->max_delinquency_date,
                    ];
                })->toArray(),
                'inquiries' => $this->creditProfile->inquiries->map(function ($inq) {
                    return [
                        'date' => $inq->date,
                        'company' => $inq->company,
                        'industry' => $inq->industry,
                    ];
                })->toArray(),
                'pdf_url' => $this->creditProfile->pdf_url,
                'document_id' => $this->creditProfile->document_id,
            ] : null,

            // Partner Details
            'partner_name' => $this->partner?->full_name,
            'partner_email' => $this->partner?->email,
            'has_sign' => (bool) $this->signedAgreement()->exists(),
        ];
    }
}
