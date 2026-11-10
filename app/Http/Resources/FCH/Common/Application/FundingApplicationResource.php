<?php

namespace App\Http\Resources\FCH\Common\Application;

use App\Http\Resources\Borrower\FundApplication\CoBorrowerResource;
use App\Http\Resources\Borrower\FundApplication\FundingApplicationDocumentResource;
use App\Models\Borrower\FundingApplication;
use App\Models\User\User;
use App\Services\FCH\Common\Funding\LenderMatchService;
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
        // A signature's dates, formatted for display through toTimezone().
        // `signed_at` is a moment, so it is shown in the viewer's timezone (the
        // X-Timezone header). `signature_date` is the calendar date the signer
        // chose - a day, not a moment - so it is formatted without shifting, or
        // it could land on the day before.
        $signatureDates = static function (array $signature): array {
            if (! empty($signature['signed_at'])) {
                $signature['signed_at'] = toTimezone($signature['signed_at'], null, 'M d, Y g:i A');
            }
            if (! empty($signature['signature_date'])) {
                $signature['signature_date'] = toTimezone($signature['signature_date'], config('app.timezone'), 'M d, Y');
            }

            return $signature;
        };

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
            'date_of_birth' => toDateOnly($this->date_of_birth, 'd M Y'),
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
            // Formatted for display (e.g. "$25,000.00"); *_original is the raw numeric
            // value for calculations and form inputs, which cannot parse the formatted one.
            'requested_amount' => currencyFormat($this->requestedAmountOrRequest()),
            'requested_amount_original' => $this->requested_amount !== null ? (float) $this->requested_amount : null,
            'purpose_of_funds' => $this->purposeOfFundsOrRequest(),
            'purpose_note' => $this->purpose_note,
            'preferred_funding_type' => $this->fundingTypeOrRequest(),
            'additional_funding_types' => $this->additional_funding_types ?? [],
            'timeframe_needed' => $this->timeframe_needed,
            'collateral_available' => $this->collateral_available,
            'funding_sequence_name' => $this->fundingSequence ? $this->fundingSequence->name : null,
            'funding_sequence_subtitle' => $this->fundingSequence ? $this->fundingSequence->subtitle : null,
            'funding_sequence_speed_to_funds' => $this->fundingSequence ? $this->fundingSequence->speed_to_funds : null,
            'funding_sequence_uses' => $this->fundingSequence ? $this->fundingSequence->uses : null,
            'funding_sequence_amount_range' => $this->fundingSequence ? $this->fundingSequence->amount_range : null,
            'funding_request_status' => $this->fundingRequest ? $this->fundingRequest->status : null,
            // What the details page needs to offer "Edit Funding Application"
            // under the same rule as the funding request page: the request the
            // edit form opens on, and whether the status is in the editable
            // list. When it isn't, locked_reason is what every viewer is shown.
            'funding_request_id' => $this->funding_request_id,
            // Editable only at a status in config `statuses.editable` - for everyone.
            'is_editable' => $this->isEditableBy($request->user()),
            'locked_reason' => $this->editingState($request->user())['locked_reason'],
            // Lender Information - the host's team only (null for anyone else).
            // From a funding request: that request's matches and lender; from a
            // loan application: matched on the Preferred and Additional Funding Types.
            ...$this->lenderInformation($request),
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
            // When it was funded - the details page's Closed (Completed) summary.
            'funded_date' => $this->funded_date?->format('Y-m-d'),
            'funded_note' => $this->funded_note,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'conversion_rate' => $this->conversion_rate,


            'signature_data' => (function () use ($signatureDates) {
                $data = is_string($this->signature_data) ? json_decode($this->signature_data, true) : $this->signature_data;

                return is_array($data) ? $signatureDates($data) : $data;
            })(),
            // The signature an edit replaced or withdrew, for showing beside the
            // current one. `requires_resign` says the current one is still owed.
            'requires_resign' => (bool) $this->requires_resign,
            'previous_signature' => (function () use ($signatureDates) {
                $data = is_string($this->previous_signature_data) ? json_decode($this->previous_signature_data, true) : $this->previous_signature_data;
                if (! is_array($data) || (empty($data['signature_name']) && empty($data['signature_image']))) {
                    return null;
                }

                return $signatureDates([
                    'signature_name' => $data['signature_name'] ?? null,
                    'signature_type' => $data['signature_type'] ?? 'typed',
                    'signature_image' => $data['signature_image'] ?? null,
                    'signature_date' => $data['signature_date'] ?? null,
                    'signed_at' => $data['signed_at'] ?? null,
                ]);
            })(),
            'partner' => $this->whenLoaded('partner'),
            'borrower' => $this->whenLoaded('borrower'),
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    private function lenderInformation(Request $request): array
    {
        // Statuses that can only be set once a lender is assigned - for every
        // viewer who changes the status, and whether one is.
        $gate = [
            // Every status outside the editable ones.
            'lender_required_statuses' => FundingApplication::lenderRequiredStatuses(),
            'has_assigned_lender' => $this->hasAssignedLender(),
        ];

        if (! FundingApplication::isHostTeam($request->user())) {
            return $gate + ['matched_lenders' => null, 'assigned_lender_ids' => null, 'can_assign_lender' => false];
        }

        return $gate + [
            // Once the lender is fixed it is always listed, even if made
            // Inactive since - the locked view shows it alone (S434-BUG-05).
            'matched_lenders' => app(LenderMatchService::class)->match(
                $this->borrower,
                $this->lenderCategories(),
                $this->canAssignLender() ? [] : $this->assignedLenderIds(),
            ),
            'assigned_lender_ids' => $this->assignedLenderIds(),
            // In an editable status, always; past them, only while none is assigned.
            'can_assign_lender' => $this->canAssignLender(),
        ];
    }
}
