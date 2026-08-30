<?php

namespace App\Http\Resources\FCH\Common\Application;

use App\Http\Resources\Borrower\FundApplication\CoBorrowerResource;
use App\Http\Resources\Borrower\FundApplication\FundingApplicationDocumentResource;
use App\Models\User\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class FundingApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');
        
        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'partner_id' => $this->partner_id,
            'borrower_id' => $this->borrower_id,
            'full_name' => $this->full_name,
            'business_name' => $this->business_name,
            'business_email' => $this->business_email,
            'business_phone_no' => $this->business_phone_no,
            'business_country' => $this->business_country,
            'business_country_code' => $this->business_country_code,
            'business_address' => $this->business_address,
            'business_city' => $this->business_city,
            'business_state' => $this->business_state,
            'business_postal_code' => $this->business_postal_code,
            'industry_type' => $this->industry_type,
            'entity_type' => $this->entity_type,
            'years_in_business' => $this->years_in_business,
            'number_of_employees' => $this->number_of_employees,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'date_of_birth' => $this->date_of_birth ? $this->date_of_birth->format('d M Y') : null,
            'ssn' => $this->ssn,
            'citizenship_status' => $this->citizenship_status,
            'street_address' => $this->street_address,
            'country' => $this->country,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'ownership' => $this->ownership,
            'has_co_borrower' => $this->has_co_borrower,
            'monthly_revenue' => $this->monthly_revenue ? currencyFormat($this->monthly_revenue) : null,
            'annual_revenue' => $this->annual_revenue ? currencyFormat($this->annual_revenue) : null,
            'business_bank_name' => $this->business_bank_name,
            'business_checking_start_date' => $this->business_checking_start_date ? $this->business_checking_start_date->format('d M Y') : null,
            'self_reported_personal_credit_score' => $this->self_reported_personal_credit_score,
            'self_reported_business_credit_score' => $this->self_reported_business_credit_score,
            'has_business_debt' => $this->has_business_debt,
            'average_monthly_deposits' => $this->borrower ? currencyFormat($this->borrower->average_monthly_deposits) : null,
            'avg_bank_balance' => $this->borrower ? currencyFormat($this->borrower->avg_bank_balance) : null,
            'time_in_business' => $this->borrower && $this->borrower->business_start_date ? calculateTimeInBusiness($this->borrower->business_start_date) : null,
            'debt_balance' => $this->debt_balance ? currencyFormat($this->debt_balance) : null,
            'requested_amount' => $this->requested_amount ? currencyFormat($this->requested_amount) : null,
            'purpose_of_funds' => $this->purpose_of_funds,
            'purpose_note' => $this->purpose_note,
            'preferred_funding_type' => $this->preferred_funding_type,
            'timeframe_needed' => $this->timeframe_needed,
            'collateral_available' => $this->collateral_available,
            'funding_sequence_name' => $this->fundingSequence ? $this->fundingSequence->name : null,
            'funding_sequence_subtitle' => $this->fundingSequence ? $this->fundingSequence->subtitle : null,
            'funding_sequence_speed_to_funds' => $this->fundingSequence ? $this->fundingSequence->speed_to_funds : null,
            'funding_sequence_uses' => $this->fundingSequence ? $this->fundingSequence->uses : null,
            'funding_sequence_amount_range' => $this->fundingSequence ? $this->fundingSequence->amount_range : null,
            'funding_request_status' => $this->fundingRequest ? $this->fundingRequest->status : null,
            'credit_pull_authorization' => $this->credit_pull_authorization,
            'terms_conditions_acceptance' => $this->terms_conditions_acceptance,
            'filed_for_bankruptcy' => $this->filed_for_bankruptcy,
            'any_pending_lawsuits' => $this->any_pending_lawsuits,
            'has_federal_loan' => $this->has_federal_loan,
            'has_federal_debt' => $this->has_federal_debt,
            'has_criminal_offense' => $this->has_criminal_offense,
            'has_parole_or_probation' => $this->has_parole_or_probation,
            'suspended_or_debarred' => $this->suspended_or_debarred,
            'any_outstanding_judgments_business' => $this->any_outstanding_judgments_business,
            'any_outstanding_judgments_owner' => $this->any_outstanding_judgments_owner,
            'federal_compliance_disclosures_explanation' => $this->federal_compliance_disclosures_explanation,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'status_class' => $this->status_class,
            'attestation_authorization' => $this->attestation_authorization,
            'co_borrowers' => CoBorrowerResource::collection($this->whenLoaded('coborrowers') ?? collect()),
            'documents' => $this->whenLoaded('fundingDocuments', function () {
                return $this->fundingDocuments->groupBy(function ($document) {
                    return $document->docType->name ?? 'Other';
                })->map(function ($documents, $type) {
                    return [
                        'type' => $type,
                        'files' => FundingApplicationDocumentResource::collection($documents),
                    ];
                })->values();
            }),
            'total_commission' => $this->commission?->total_commission,
            'admin_commission' => $this->commission?->admin_commission,
            'partner_commission' => $this->commission?->partner_commission,
            'total_commission_amount' => $this->commission?->total_commission_amount,
            'admin_commission_amount' => $this->commission?->admin_commission_amount,
            'partner_commission_amount' => $this->commission?->partner_commission_amount,
            'commission_status' => $this->commission?->commission_status,
            'payment_status' => $this->commission?->payment_status,

            'total_funded_amount' => $this->total_funded_amount,
            'funded_note' => $this->funded_note,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,


            'download_histories' => $this->whenLoaded('downloadHistories'),
            'signature_data' => (function() use ($timezone) {
                $data = is_string($this->signature_data) ? json_decode($this->signature_data, true) : $this->signature_data;
                if (is_array($data) && isset($data['signed_at'])) {
                    $data['signed_at'] = Carbon::parse($data['signed_at'])->timezone($timezone)->format('Y-m-d h:i A');
                }
                return $data;
            })(),
            'partner' => $this->whenLoaded('partner'),
            'borrower' => $this->whenLoaded('borrower'),
            'created_at' => $this->created_at ? $this->created_at->timezone($timezone)->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->timezone($timezone)->format('Y-m-d H:i:s') : null,
        ];
    }
}
