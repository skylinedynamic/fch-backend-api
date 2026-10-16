<?php

namespace App\Http\Resources\FCH\EmbedForm;

use App\Models\EmbedForm\EmbedFormSubmission;
use App\Services\FCH\Common\Scoring\CreditScoreService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EmbedForm\EmbedFormSubmission */
class EmbedFormSubmissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Grouped by the funding application steps the applicant filled in, so the
     * partner portal renders the submission in the order it was collected.
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            [
                'id' => $this->id,
                'submission_uid' => $this->submission_uid,
                'partner_id' => $this->partner_id,
                'form_config_id' => $this->form_config_id,
            ],
            $this->businessInformation(),
            $this->ownershipInformation(),
            $this->financialInformation(),
            $this->fundingRequest(),
            $this->disclosures(),
            $this->federalCompliance(),
            $this->attestation(),
            $this->tracking(),
            $this->relations(),
            $this->legacyConsumerFields(),
            [
                'created_at' => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
                'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
            ],
        );
    }

    protected function businessInformation(): array
    {
        return [
            'full_name' => $this->full_name,
            'business_name' => $this->business_name,
            'business_email' => $this->business_email,
            'business_phone_no' => $this->business_phone_no,
            'business_phone_display' => formatPhoneDisplay($this->business_phone_no),
            'business_country' => $this->business_country,
            'business_country_code' => $this->business_country_code,
            'business_address' => $this->business_address,
            'business_city' => $this->business_city,
            'business_state' => $this->business_state,
            'business_postal_code' => $this->business_postal_code,
            'industry_type' => $this->industry_type,
            'entity_type' => $this->entity_type,
            // Stored as the nine digits; shown as an EIN is written.
            'business_ein' => $this->business_ein,
            'business_ein_display' => $this->einDisplay(),
            // Worked out from the business start date; older submissions picked it.
            'years_in_business' => $this->years_in_business,
            'number_of_employees' => $this->number_of_employees,
        ];
    }

    /**
     * The SSN never leaves the server in the clear - only the last four digits,
     * which is all the partner portal shows.
     */
    protected function ownershipInformation(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'applicant_name' => $this->applicant_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_display' => formatPhoneDisplay($this->phone),
            'date_of_birth' => toDateOnly($this->date_of_birth),
            'last_4_ssn' => $this->lastFourSsn(),
            'citizenship_status' => $this->citizenship_status,
            'street_address' => $this->street_address,
            'country' => $this->country,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'ownership' => $this->ownership,
            'has_co_borrower' => (bool) $this->has_co_borrower,
        ];
    }

    /**
     * Financial answers, with the credit score band already resolved.
     *
     * The band is computed here, server side, rather than left to each screen to
     * work out - that duplication is what let 710 and 720 be labelled "Fair" in
     * the portal while the rules said "Good".
     */
    protected function financialInformation(): array
    {
        $scoring = app(CreditScoreService::class);
        $score = $this->credit_score ?? $this->self_reported_personal_credit_score;
        $band = $scoring->band($score);

        return [
            // Self-reported by the applicant - never a bureau-verified figure,
            // and the UI is required to label it as such.
            'credit_score' => $this->credit_score,
            'credit_score_display' => $scoring->normalize($score),
            'credit_score_band' => $band['key'] ?? null,
            'credit_score_band_label' => $band['label'] ?? null,
            'credit_score_band_tone' => $band['tone'] ?? null,
            'credit_score_is_self_reported' => true,

            'monthly_revenue' => $this->monthly_revenue,
            'monthly_revenue_formatted' => $this->money($this->monthly_revenue),
            'annual_revenue' => $this->annual_revenue,
            'annual_revenue_formatted' => $this->money($this->annual_revenue),
            'business_bank_name' => $this->business_bank_name,
            'business_checking_start_date' => toDateOnly($this->business_checking_start_date),
            'self_reported_personal_credit_score' => $this->self_reported_personal_credit_score,
            'self_reported_business_credit_score' => $this->self_reported_business_credit_score,
            'has_business_debt' => (bool) $this->has_business_debt,
            'debt_balance' => $this->debt_balance,
            'debt_balance_formatted' => $this->money($this->debt_balance),
        ];
    }

    protected function fundingRequest(): array
    {
        return [
            'currency' => $this->currencyCode(),
            'currency_symbol' => $this->currencySymbol(),
            'requested_amount' => $this->requested_amount,
            'requested_amount_formatted' => $this->requested_amount !== null
                ? currencyFormat($this->requested_amount, 2, $this->currencySymbol())
                : null,
            'purpose_of_funds' => $this->purpose_of_funds,
            'purpose_note' => $this->purpose_note,
            'approved_funding' => $this->approved_funding,
            'preferred_funding_type' => $this->preferred_funding_type,
            'additional_funding_types' => $this->additional_funding_types ?? [],
            'timeframe_needed' => $this->timeframe_needed,
            'collateral_available' => $this->collateral_available,
        ];
    }

    protected function disclosures(): array
    {
        return [
            'credit_pull_authorization' => (bool) $this->credit_pull_authorization,
            'terms_conditions_acceptance' => (bool) $this->terms_conditions_acceptance,
        ];
    }

    protected function federalCompliance(): array
    {
        return [
            'filed_for_bankruptcy' => (bool) $this->filed_for_bankruptcy,
            'any_pending_lawsuits' => (bool) $this->any_pending_lawsuits,
            'has_federal_loan' => (bool) $this->has_federal_loan,
            'has_federal_debt' => (bool) $this->has_federal_debt,
            'has_criminal_offense' => (bool) $this->has_criminal_offense,
            'has_parole_or_probation' => (bool) $this->has_parole_or_probation,
            'suspended_or_debarred' => (bool) $this->suspended_or_debarred,
            'any_outstanding_judgments_business' => (bool) $this->any_outstanding_judgments_business,
            'any_outstanding_judgments_owner' => (bool) $this->any_outstanding_judgments_owner,
            'federal_compliance_disclosures_explanation' => $this->federal_compliance_disclosures_explanation,
        ];
    }

    protected function attestation(): array
    {
        return [
            'attestation_authorization' => (bool) $this->attestation_authorization,
            'signature_name' => $this->signature_name,
            'esign_consent' => (bool) $this->esign_consent,
            'terms_consent' => (bool) $this->terms_consent,
            'credit_consent' => (bool) $this->credit_consent,
            'legal_binding_consent' => (bool) $this->legal_binding_consent,
            'signature_data' => $this->signature_data,
            // signature_data.signed_at is stored UTC. Converted here rather than
            // left to each client to format, so the moment a signature was given
            // reads the same wherever it is shown.
            'signed_at' => toTimezone($this->signature_data['signed_at'] ?? null, null, 'Y-m-d H:i:s'),
            'application_version' => $this->application_version,
            'submission_timezone' => $this->submission_timezone,
        ];
    }

    protected function tracking(): array
    {
        return [
            'sales_rep' => $this->sales_rep,
            'sales_rep_user_id' => $this->sales_rep_user_id,
            'referral_source' => $this->referral_source,
            'referral_source_other' => $this->referral_source_other,
            'status' => $this->status,
            'ip_address' => $this->ip_address,
            'origin_url' => $this->origin_url,
        ];
    }

    protected function relations(): array
    {
        return [
            'partner' => $this->whenLoaded('partner', function () {
                return [
                    'id' => $this->partner?->id,
                    'full_name' => $this->partner?->full_name,
                    'company_name' => $this->partner?->company_name,
                ];
            }),

            'co_borrowers' => EmbedFormCoBorrowerResource::collection($this->whenLoaded('coBorrowers')),

            // Free trial borrower created from this submission
            'converted_borrower_id' => $this->converted_borrower_id,
            'converted_borrower' => $this->whenLoaded('convertedBorrower', function () {
                return $this->convertedBorrower ? [
                    'id' => $this->convertedBorrower->id,
                    'full_name' => personName($this->convertedBorrower->first_name ?? null, $this->convertedBorrower->middle_name ?? null, $this->convertedBorrower->last_name ?? null),
                    'status' => $this->convertedBorrower->status,
                    'is_free_trail' => (bool) $this->convertedBorrower->is_free_trail,
                    'free_trail_end_date' => toDateOnly($this->convertedBorrower->free_trail_end_date),
                ] : null;
            }),

            // Funding application built from this submission at conversion time
            'converted_funding_application_id' => $this->converted_funding_application_id,
            'converted_funding_application' => $this->whenLoaded('convertedFundingApplication', function () {
                return $this->convertedFundingApplication ? [
                    'id' => $this->convertedFundingApplication->id,
                    'application_number' => $this->convertedFundingApplication->application_number,
                    'status' => $this->convertedFundingApplication->status,
                ] : null;
            }),

            'attachments' => EmbedFormAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }

    /**
     * Answers from before the funding application rewrite.
     *
     * Only emitted for submissions that actually carry them, so the partner
     * portal can show the old consumer-loan section for historic rows without
     * rendering an empty card on every new one.
     */
    protected function legacyConsumerFields(): array
    {
        /** @var EmbedFormSubmission $submission */
        $submission = $this->resource;

        if (!$submission->hasLegacyConsumerData()) {
            return ['has_legacy_consumer_data' => false];
        }

        return [
            'has_legacy_consumer_data' => true,
            'birthday' => toDateOnly($this->birthday),
            'desired_funding_amount' => $this->desired_funding_amount,
            'desired_funding_amount_formatted' => $this->money($this->desired_funding_amount),
            'home_address' => $this->home_address,
            'postal_code' => $this->postal_code,
            'monthly_housing_payment' => $this->monthly_housing_payment,
            'monthly_housing_payment_formatted' => $this->money($this->monthly_housing_payment),
            'housing_status' => $this->housing_status,
            'employer_name' => $this->employer_name,
            'employer_phone' => $this->employer_phone,
            'employer_address' => $this->employer_address,
            'occupation' => $this->occupation,
            'annual_income' => $this->annual_income,
            'annual_income_formatted' => $this->money($this->annual_income),
        ];
    }

    /** The requested amount's currency; a submission from before the choice is in dollars. */
    protected function currencyCode(): string
    {
        return $this->currency ?: array_key_first(config('funding_application.embed_form.currencies'));
    }

    protected function currencySymbol(): string
    {
        return config('funding_application.embed_form.currencies.' . $this->currencyCode(), '$');
    }

    /** XX-XXXXXXX, or the value as stored when it is not nine digits. */
    protected function einDisplay(): ?string
    {
        if (!$this->business_ein) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->business_ein);

        return strlen($digits) === 9 ? substr($digits, 0, 2) . '-' . substr($digits, 2) : $this->business_ein;
    }

    protected function money($value): ?string
    {
        return $value !== null ? currencyFormat($value, 2) : null;
    }

    /**
     * Last four SSN digits, decrypted just far enough to display.
     */
    protected function lastFourSsn(): ?string
    {
        if (!$this->ssn_encrypted) {
            return null;
        }

        try {
            return substr(\Illuminate\Support\Facades\Crypt::decryptString($this->ssn_encrypted), -4);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
