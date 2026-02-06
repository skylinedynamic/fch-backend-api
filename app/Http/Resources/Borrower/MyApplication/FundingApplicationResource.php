<?php

namespace App\Http\Resources\Borrower\MyApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                                         => $this->id,
            'borrower_id'                                => $this->borrower_id,
            'status'                                     => $this->status,
            'full_name'                                  => $this->full_name,
            'business_name'                              => $this->business_name,
            'business_email'                             => $this->business_email,
            'business_phone_no'                          => $this->business_phone_no,
            'business_country'                           => $this->business_country,
            'business_country_code'                      => $this->business_country_code,
            'business_address'                           => $this->business_address,
            'business_city'                              => $this->business_city,
            'business_state'                             => $this->business_state,
            'business_postal_code'                       => $this->business_postal_code,
            'industry_type'                              => $this->industry_type,
            'entity_type'                                => $this->entity_type,
            'years_in_business'                          => $this->years_in_business,
            'number_of_employees'                        => $this->number_of_employees,
            'first_name'                                 => $this->first_name,
            'last_name'                                  => $this->last_name,
            'date_of_birth'                              => $this->date_of_birth,
            'ssn'                                        => $this->ssn,
            'citizenship_status'                         => $this->citizenship_status,
            'street_address'                             => $this->street_address,
            'country'                                    => $this->country,
            'city'                                       => $this->city,
            'state'                                      => $this->state,
            'zip_code'                                   => $this->zip_code,
            'ownership'                                  => $this->ownership,
            'has_co_borrower'                            => (bool) $this->has_co_borrower,
            'monthly_revenue'                            => $this->monthly_revenue,
            'annual_revenue'                             => $this->annual_revenue,
            'business_bank_name'                         => $this->business_bank_name,
            'business_checking_start_date'               => $this->business_checking_start_date,
            'self_reported_personal_credit_score'        => $this->self_reported_personal_credit_score,
            'self_reported_business_credit_score'        => $this->self_reported_business_credit_score,
            'has_business_debt'                          => (bool) $this->has_business_debt,
            'debt_balance'                               => $this->debt_balance,
            'requested_amount'                           => $this->requested_amount,
            'purpose_of_funds'                           => $this->purpose_of_funds,
            'preferred_funding_type'                     => $this->preferred_funding_type,
            'timeframe_needed'                           => $this->timeframe_needed,
            'collateral_available'                       => $this->collateral_available,
            'credit_pull_authorization'                  => (bool) $this->credit_pull_authorization,
            'terms_conditions_acceptance'                => (bool) $this->terms_conditions_acceptance,
            'filed_for_bankruptcy'                       => isset($this->filed_for_bankruptcy) ? (bool) $this->filed_for_bankruptcy : null,
            'any_pending_lawsuits'                       => isset($this->any_pending_lawsuits) ? (bool) $this->any_pending_lawsuits : null,
            'has_federal_loan'                           => isset($this->has_federal_loan) ? (bool) $this->has_federal_loan : null,
            'has_federal_debt'                           => isset($this->has_federal_debt) ? (bool) $this->has_federal_debt : null,
            'has_criminal_offense'                       => isset($this->has_criminal_offense) ? (bool) $this->has_criminal_offense : null,
            'has_parole_or_probation'                    => isset($this->has_parole_or_probation) ? (bool) $this->has_parole_or_probation : null,
            'suspended_or_debarred'                      => isset($this->suspended_or_debarred) ? (bool) $this->suspended_or_debarred : null,
            'any_outstanding_judgments_business'         => isset($this->any_outstanding_judgments_business) ? (bool) $this->any_outstanding_judgments_business : null,
            'any_outstanding_judgments_owner'            => isset($this->any_outstanding_judgments_owner) ? (bool) $this->any_outstanding_judgments_owner : null,
            'federal_compliance_disclosures_explanation' => $this->federal_compliance_disclosures_explanation,
            'attestation_authorization'                  => (bool) $this->attestation_authorization,
            'date'                                       => $this->created_at?->format('d M Y'),
        ];
    }
}
