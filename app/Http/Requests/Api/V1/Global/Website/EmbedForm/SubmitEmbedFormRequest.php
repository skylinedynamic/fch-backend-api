<?php

namespace App\Http\Requests\Api\V1\Global\Website\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\BorrowerDocType;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\User\User;
use App\Helpers\ApiResponse;
use App\Rules\UsPhoneNumber;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validation for the public embedded funding application.
 *
 * Mirrors the borrower portal's own application requests
 * (StoreBusinessInfoRequest, StoreOwnershipInfoRequest, StoreFinancialInfoRequest,
 * StoreFundingRequestRequest, DisclosuresAndConsentRequest,
 * FederalComplianceRequest, AttestationAndAuthorizationRequest) so a submission
 * that passes here is complete enough to become a funding application without a
 * partner having to fill gaps by hand.
 *
 * One deliberate difference: the portal collects an SSN as nine bare digits,
 * while this form masks as the applicant types. Both shapes are accepted and
 * normalised to digits in prepareForValidation().
 */
class SubmitEmbedFormRequest extends BaseApiRequest
{
    /**
     * Gate the submission on the partner's "Allowed Domains" list.
     *
     * Deliberately here rather than in the controller: a FormRequest validates
     * on resolution, so a controller-side check runs only *after* validation.
     * A blocked site would get a 422 listing every field it failed - which both
     * hands out the schema and buries the real reason. authorize() runs first,
     * so a disallowed origin gets a plain 403 and learns nothing else.
     */
    public function authorize(): bool
    {
        $config = $this->embedFormConfig();

        // No config means the key is unknown or inactive; the controller answers
        // that with a 404, and it is not this method's job to pre-empt it.
        if (!$config) {
            return true;
        }

        return app(EmbedFormService::class)->isOriginAllowed($config, $this);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            ApiResponse::forbidden(__('embed_form.origin_not_allowed'))
        );
    }

    /**
     * The form this submission is for, resolved once per request.
     */
    protected function embedFormConfig(): ?EmbedFormConfig
    {
        static $config;
        static $resolved = false;

        if (!$resolved) {
            $config = EmbedFormConfig::query()
                ->where('public_key', (string) $this->route('key'))
                ->where('is_active', true)
                ->first();
            $resolved = true;
        }

        return $config;
    }

    /**
     * Normalise the masked inputs the public form produces.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->filled('ssn')) {
            $merge['ssn'] = preg_replace('/\D/', '', (string) $this->input('ssn'));
        }

        // Currency inputs arrive with the thousands separators the form renders.
        foreach (['requested_amount', 'monthly_revenue', 'annual_revenue', 'debt_balance'] as $money) {
            if ($this->filled($money)) {
                $merge[$money] = preg_replace('/[^\d.\-]/', '', (string) $this->input($money));
            }
        }

        $coBorrowers = $this->input('co_borrowers');
        if (is_array($coBorrowers)) {
            foreach ($coBorrowers as $index => $coBorrower) {
                if (!empty($coBorrower['ssn'])) {
                    $coBorrowers[$index]['ssn'] = preg_replace('/\D/', '', (string) $coBorrower['ssn']);
                }
            }
            $merge['co_borrowers'] = $coBorrowers;
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return array_merge(
            $this->businessInfoRules(),
            $this->ownershipInfoRules(),
            $this->coBorrowerRules(),
            $this->financialInfoRules(),
            $this->fundingRequestRules(),
            $this->disclosureRules(),
            $this->federalComplianceRules(),
            $this->attestationRules(),
            $this->trackingRules(),
            $this->documentRules(),
        );
    }

    /**
     * Business Information - mirrors StoreBusinessInfoRequest.
     */
    protected function businessInfoRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_email' => ['required', 'email', 'max:255'],
            'business_phone_no' => ['required', 'string', 'max:255', new UsPhoneNumber()],
            'business_country' => ['required', 'string', 'max:255'],
            'business_country_code' => ['required', 'string', 'max:10'],
            'business_address' => ['required', 'string', 'max:255'],
            'business_city' => ['required', 'string', 'max:255'],
            'business_state' => ['required', 'string', 'max:255'],
            'business_postal_code' => array_merge(['required'], $this->postalCodeRules()),
            'industry_type' => ['required', 'string', 'max:255'],
            'entity_type' => ['required', 'string', 'max:255'],
            'years_in_business' => ['required', 'string', 'max:255'],
            'number_of_employees' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Ownership Information - mirrors StoreOwnershipInfoRequest.
     *
     * `email` is required here where the portal's version leaves it out: the
     * portal already knows the logged-in borrower, and this form does not.
     */
    protected function ownershipInfoRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:255', new UsPhoneNumber()],
            // An applicant must be an adult, and cannot be born in the future.
            'date_of_birth' => [
                'required',
                'date',
                'before:today',
                'before_or_equal:' . now()->subYears((int) config('funding_application.embed_form.minimum_age', 18))->toDateString(),
            ],
            'ssn' => ['required', 'string', 'digits:9'],
            'citizenship_status' => ['required', 'string', 'max:255'],
            'street_address' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'zip_code' => array_merge(['required'], $this->postalCodeRules()),
            'ownership' => ['required', 'numeric', 'min:0', 'max:100'],
            'has_co_borrower' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Co-borrowers - mirrors the portal's co-borrower validation endpoint.
     *
     * Each entry is only required once the applicant has added the row, which is
     * what `required_with` on the array item expresses.
     */
    protected function coBorrowerRules(): array
    {
        return [
            'co_borrowers' => ['nullable', 'array', 'max:10'],
            'co_borrowers.*.first_name' => ['required', 'string', 'max:255'],
            'co_borrowers.*.last_name' => ['required', 'string', 'max:255'],
            'co_borrowers.*.title_role' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.ownership_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'co_borrowers.*.email' => ['required', 'email', 'max:255'],
            'co_borrowers.*.mobile_phone' => ['nullable', 'string', 'max:255', new UsPhoneNumber()],
            'co_borrowers.*.home_phone' => ['nullable', 'string', 'max:255', new UsPhoneNumber()],
            'co_borrowers.*.date_of_birth' => [
                'nullable',
                'date',
                'before:today',
                'before_or_equal:' . now()->subYears((int) config('funding_application.embed_form.minimum_age', 18))->toDateString(),
            ],
            'co_borrowers.*.ssn' => ['nullable', 'string', 'digits:9'],
            'co_borrowers.*.street_address' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.country' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.city' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.state' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.zip_code' => array_merge(['nullable'], $this->postalCodeRules()),
            'co_borrowers.*.drivers_license_number' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.drivers_license_state' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Financial Information - mirrors StoreFinancialInfoRequest.
     */
    protected function financialInfoRules(): array
    {
        return [
            'monthly_revenue' => array_merge(['required'], $this->amountRules('monthly_revenue')),
            'annual_revenue' => array_merge(['nullable'], $this->amountRules('annual_revenue')),
            'business_bank_name' => ['nullable', 'string', 'max:255'],
            'business_checking_start_date' => ['required', 'date', 'before_or_equal:today'],

            // Numeric, on the same 300-850 scale the band table is defined over,
            // so a score means one thing everywhere. It is self-reported - the
            // UI is required to label it as such and never present it as a
            // bureau-verified figure.
            // Optional: an applicant who does not know their score should not be
            // blocked from applying over a figure that is self-reported anyway.
            'credit_score' => [
                'nullable',
                'integer',
                'min:' . (int) config('funding_application.credit_score.min', 300),
                'max:' . (int) config('funding_application.credit_score.max', 850),
            ],
            'self_reported_business_credit_score' => [
                'nullable',
                'string',
                Rule::in(config('funding_application.credit_score.business_score_options', [])),
            ],

            'has_business_debt' => ['required', 'boolean'],
            'debt_balance' => array_merge(
                ['required_if:has_business_debt,true,1', 'nullable'],
                $this->amountRules('debt_balance')
            ),
        ];
    }

    /**
     * A US ZIP code: exactly five digits.
     *
     * `digits` rather than `size`, so "1234x" and " 12345" are rejected rather
     * than counted as five characters.
     */
    protected function postalCodeRules(): array
    {
        return [
            'string',
            'digits:' . (int) config('funding_application.embed_form.postal_code_length', 5),
        ];
    }

    /**
     * Numeric bounds for a currency field, from config so a limit can be
     * changed without editing validation code.
     */
    protected function amountRules(string $field): array
    {
        $bounds = config("funding_application.embed_form.amounts.{$field}", []);

        $rules = ['numeric'];

        if (isset($bounds['min'])) {
            $rules[] = 'min:' . $bounds['min'];
        }

        if (isset($bounds['max'])) {
            $rules[] = 'max:' . $bounds['max'];
        }

        return $rules;
    }

    /**
     * Funding Request - mirrors StoreFundingRequestRequest.
     *
     * `approved_funding` is required in the portal because a borrower gets there
     * from an approved funding request. A public applicant has none, so it is
     * not collected at all here - a partner sets it during review.
     */
    protected function fundingRequestRules(): array
    {
        return [
            'requested_amount' => array_merge(['required'], $this->amountRules('requested_amount')),
            'purpose_of_funds' => ['required', 'string', 'max:500'],
            'purpose_note' => ['nullable', 'string', 'max:500'],
            'preferred_funding_type' => ['required', 'string', 'max:255'],
            'timeframe_needed' => ['nullable', 'string', 'max:255'],
            'collateral_available' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Disclosures and consent - mirrors DisclosuresAndConsentRequest.
     */
    protected function disclosureRules(): array
    {
        return [
            'credit_pull_authorization' => ['required', 'accepted'],
            'terms_conditions_acceptance' => ['required', 'accepted'],
        ];
    }

    /**
     * Federal Compliance Disclosures - mirrors FederalComplianceRequest,
     * including its rule that any "yes" answer requires an explanation.
     */
    protected function federalComplianceRules(): array
    {
        $flags = [
            'filed_for_bankruptcy',
            'any_pending_lawsuits',
            'has_federal_loan',
            'has_federal_debt',
            'has_criminal_offense',
            'has_parole_or_probation',
            'suspended_or_debarred',
            'any_outstanding_judgments_business',
            'any_outstanding_judgments_owner',
        ];

        $rules = [];
        foreach ($flags as $flag) {
            $rules[$flag] = ['bail', 'required', 'boolean'];
        }

        $rules['federal_compliance_disclosures_explanation'] = [
            'bail',
            Rule::requiredIf(function () use ($flags) {
                foreach ($flags as $flag) {
                    if ($this->boolean($flag)) {
                        return true;
                    }
                }

                return false;
            }),
            'nullable',
            'string',
            'max:5000',
        ];

        return $rules;
    }

    /**
     * Attestation and authorization - mirrors AttestationAndAuthorizationRequest.
     */
    protected function attestationRules(): array
    {
        return [
            'signature_name' => ['required', 'string', 'min:3', 'max:255', 'regex:/^(?!\s*$).+/'],
            'esign_consent' => ['required', 'accepted'],
            'terms_consent' => ['required', 'accepted'],
            'credit_consent' => ['required', 'accepted'],
            'legal_binding_consent' => ['required', 'accepted'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'application_version' => ['nullable', 'string', 'max:50'],
            'consents_data' => ['nullable', 'string'],
        ];
    }

    /**
     * Referral tracking and the bot honeypot, both carried over unchanged.
     */
    protected function trackingRules(): array
    {
        $otherSource = config('funding_application.embed_form.referral_source_other', 'Other');

        return [
            // The rep is picked from the owning partner's active users, not
            // typed - free text here could never be tied back to a real person.
            'sales_rep_user_id' => ['nullable', 'integer', Rule::in($this->partnerUserIds())],

            'referral_source' => [
                'nullable',
                'string',
                Rule::in(config('funding_application.embed_form.referral_sources', [])),
            ],
            'referral_source_other' => [
                Rule::requiredIf(fn () => $this->input('referral_source') === $otherSource),
                'nullable',
                'string',
                'max:255',
            ],

            '_hp_company' => ['nullable', 'max:0'],
        ];
    }

    /**
     * Ids of the active users belonging to the partner who owns this form.
     *
     * Scoped to the form's own partner so one partner's rep list can never be
     * probed - or assigned - through another partner's public form.
     */
    protected function partnerUserIds(): array
    {
        $config = $this->embedFormConfig();

        if (!$config) {
            return [];
        }

        return User::salesRepresentatives($config->partner_id)
            ->pluck('id')
            ->all();
    }

    /**
     * Uploads, one array per borrower document type.
     *
     * Fields arrive as `documents[<doc type id>][]` so a new document type added
     * in the admin shows up on the form without a code change. Unknown ids are
     * rejected rather than silently filed under nothing.
     */
    protected function documentRules(): array
    {
        return [
            'documents' => ['nullable', 'array'],
            'documents.*' => ['nullable', 'array', 'max:10'],
            'documents.*.*' => [
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif',
                'max:10240',
            ],
        ];
    }

    /**
     * Reject uploads filed under a document type that does not exist.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $documents = $this->file('documents');

            if (!is_array($documents) || empty($documents)) {
                return;
            }

            $validIds = BorrowerDocType::query()->pluck('id')->all();

            foreach (array_keys($documents) as $docTypeId) {
                if (!in_array((int) $docTypeId, $validIds, true)) {
                    $validator->errors()->add(
                        "documents.{$docTypeId}",
                        __('embed_form.document_type_invalid')
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'full_name.required' => __('embed_form.full_name_required'),
            'business_name.required' => __('embed_form.business_name_required'),
            'business_email.email' => __('embed_form.email_invalid'),
            'first_name.required' => __('embed_form.first_name_required'),
            'last_name.required' => __('embed_form.last_name_required'),
            'email.required' => __('embed_form.email_required'),
            'email.email' => __('embed_form.email_invalid'),
            'ssn.digits' => __('embed_form.ssn_format'),
            'co_borrowers.*.ssn.digits' => __('embed_form.ssn_format'),
            'ownership.max' => __('embed_form.ownership_max'),
            'business_postal_code.digits' => __('embed_form.postal_code_digits'),
            'zip_code.digits' => __('embed_form.postal_code_digits'),
            'co_borrowers.*.zip_code.digits' => __('embed_form.postal_code_digits'),
            'monthly_revenue.required' => __('embed_form.monthly_revenue_required'),
            'phone.required' => __('embed_form.phone_required'),
            'date_of_birth.before_or_equal' => __('embed_form.minimum_age', ['age' => config('funding_application.embed_form.minimum_age', 18)]),
            'co_borrowers.*.date_of_birth.before_or_equal' => __('embed_form.minimum_age', ['age' => config('funding_application.embed_form.minimum_age', 18)]),
            'credit_score.min' => __('embed_form.credit_score_min'),
            'credit_score.max' => __('embed_form.credit_score_max'),
            'sales_rep_user_id.in' => __('embed_form.sales_rep_invalid'),
            'referral_source.in' => __('embed_form.referral_source_invalid'),
            'referral_source_other.required' => __('embed_form.referral_source_other_required'),
            'debt_balance.required_if' => __('embed_form.debt_balance_required'),
            'requested_amount.required' => __('embed_form.requested_amount_required'),
            'credit_pull_authorization.accepted' => __('embed_form.credit_pull_required'),
            'terms_conditions_acceptance.accepted' => __('embed_form.terms_required'),
            'federal_compliance_disclosures_explanation.required' => __('embed_form.explanation_required'),
            'signature_name.required' => __('embed_form.signature_required'),
            'signature_name.min' => __('embed_form.signature_min'),
            'esign_consent.accepted' => __('embed_form.esign_required'),
            'terms_consent.accepted' => __('embed_form.terms_required'),
            'credit_consent.accepted' => __('embed_form.credit_pull_required'),
            'legal_binding_consent.accepted' => __('embed_form.legal_binding_required'),
            '_hp_company.max' => __('embed_form.honeypot_triggered'),
            'documents.*.*.mimes' => __('embed_form.document_mimes'),
            'documents.*.*.max' => __('embed_form.document_max'),
        ];
    }
}
