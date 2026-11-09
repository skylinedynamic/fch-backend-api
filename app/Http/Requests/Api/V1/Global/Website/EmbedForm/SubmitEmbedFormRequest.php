<?php

namespace App\Http\Requests\Api\V1\Global\Website\EmbedForm;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\BorrowerDocType;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\User\User;
use App\Helpers\ApiResponse;
use App\Rules\EmbedFormApplicantEmail;
use App\Rules\UsPhoneNumber;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use App\Rules\KnownFundingType;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Rules\UsPostalCode;
use App\Rules\DateOfBirth;
use App\Rules\Ssn;
use App\Rules\FicoScore;
use App\Rules\BusinessEin;

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

        // No config means the key is unknown or inactive. Answered here rather
        // than in the controller, which only runs after validation - by then
        // the caller has been handed a 422 naming every field of the form.
        if (!$config) {
            throw new HttpResponseException(
                ApiResponse::notFound(__('embed_form.form_not_found_or_inactive'))
            );
        }

        return app(EmbedFormService::class)->isOriginAllowed($config, $this);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            ApiResponse::forbidden(__('embed_form.origin_not_allowed'))
        );
    }

    private ?EmbedFormConfig $resolvedConfig = null;

    private bool $configResolved = false;

    /**
     * The form this submission is for, resolved once per request.
     *
     * Kept on this request object, not in a static: under Octane one worker
     * serves many requests, and a static would hand every later request the
     * first form's allow-list and partner.
     */
    protected function embedFormConfig(): ?EmbedFormConfig
    {
        if (!$this->configResolved) {
            $this->resolvedConfig = EmbedFormConfig::query()
                ->where('public_key', (string) $this->route('key'))
                ->where('is_active', true)
                ->first();
            $this->configResolved = true;
        }

        return $this->resolvedConfig;
    }

    /**
     * Normalise the masked inputs the public form produces.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        // Only a writing of an SSN is reduced; one carrying letters is left for
        // the Ssn rule to refuse rather than passing with them stripped.
        if ($this->filled('ssn')) {
            $merge['ssn'] = Ssn::canonical($this->input('ssn'));
        }

        // Shown as XX-XXXXXXX; the number itself is the nine digits. Anything that
        // is not an EIN writing is left for BusinessEin to refuse.
        if ($this->filled('business_ein') && !preg_match('/[^\d\s-]/', (string) $this->input('business_ein'))) {
            $merge['business_ein'] = BusinessEin::normalize($this->input('business_ein'));
        }

        // Phones as the borrower and partner portals store them, (512) 234-5678,
        // not the form's +15122345678. A number that is not a US phone is left
        // as it came, for UsPhoneNumber to refuse.
        foreach (['phone', 'business_phone_no'] as $phone) {
            if ($this->filled($phone)) {
                $merge[$phone] = formatPhoneDisplay((string) $this->input($phone));
            }
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
                    $coBorrowers[$index]['ssn'] = Ssn::canonical($coBorrower['ssn']);
                }
                foreach (['mobile_phone', 'home_phone'] as $phone) {
                    if (!empty($coBorrower[$phone])) {
                        $coBorrowers[$index][$phone] = formatPhoneDisplay((string) $coBorrower[$phone]);
                    }
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
            // What "Other" is, as the borrower profile asks it.
            'industry_type_note' => ['required_if:industry_type,' . config('borrower.industry_type_other'), 'nullable', 'string', 'max:255'],
            'entity_type' => ['required', 'string', 'max:255'],
            'entity_type_note' => ['required_if:entity_type,' . config('borrower.entity_type_other'), 'nullable', 'string', 'max:255'],
            // Optional; when given, nine digits written XX-XXXXXXX, stored as the digits.
            'business_ein' => ['nullable', 'string', new BusinessEin()],
            // Not asked: worked out from the business start date (financial step).
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
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                // Free, or already this form's partner's Borrower; never another
                // partner's borrower, nor a Partner/Employee/User.
                new EmbedFormApplicantEmail($this->embedFormConfig()?->partner_id),
            ],
            'phone' => ['required', 'string', 'max:255', new UsPhoneNumber()],
            // An applicant must be an adult, and cannot be born in the future.
            'date_of_birth' => ['required', new DateOfBirth()],
            'ssn' => ['required', 'string', new Ssn()],
            'citizenship_status' => ['required', 'string', 'max:255'],
            'street_address' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
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
            'co_borrowers.*.middle_name' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.last_name' => ['required', 'string', 'max:255'],
            'co_borrowers.*.title_role' => ['nullable', 'string', 'max:255'],
            'co_borrowers.*.ownership_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'co_borrowers.*.email' => ['required', 'email', 'max:255'],
            'co_borrowers.*.mobile_phone' => ['nullable', 'string', 'max:255', new UsPhoneNumber()],
            'co_borrowers.*.home_phone' => ['nullable', 'string', 'max:255', new UsPhoneNumber()],
            'co_borrowers.*.date_of_birth' => ['nullable', new DateOfBirth()],
            'co_borrowers.*.ssn' => ['nullable', 'string', new Ssn()],
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
            'credit_score' => ['nullable', new FicoScore()],
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
     * A US ZIP code, validated by the same rule every other zip field in both
     * portals uses, so the public form cannot accept something the portals
     * would reject.
     */
    protected function postalCodeRules(): array
    {
        return ['string', new UsPostalCode()];
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
            // An Active lender product category, as offered (Lenders > Product Categories).
            'preferred_funding_type' => ['required', 'string', 'max:255', new KnownFundingType('embed_form.preferred_funding_type_invalid')],
            // The others the applicant would also consider - never the preferred one again.
            'additional_funding_types' => ['nullable', 'array'],
            'additional_funding_types.*' => [
                'string',
                'distinct',
                new KnownFundingType('embed_form.additional_funding_type_invalid'),
                'different:preferred_funding_type',
            ],
            'currency' => ['required', 'string', Rule::in(array_keys(config('funding_application.embed_form.currencies', [])))],
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
            'monthly_revenue.required' => __('embed_form.monthly_revenue_required'),
            'phone.required' => __('embed_form.phone_required'),
            'credit_score.min' => __('embed_form.credit_score_min'),
            'credit_score.max' => __('embed_form.credit_score_max'),
            'sales_rep_user_id.in' => __('embed_form.sales_rep_invalid'),
            'referral_source.in' => __('embed_form.referral_source_invalid'),
            'referral_source_other.required' => __('embed_form.referral_source_other_required'),
            'debt_balance.required_if' => __('embed_form.debt_balance_required'),
            // The borrower profile's own words for the same fields.
            'entity_type_note.required_if' => __('borrower_validation.business_entity_type_note_required_if'),
            'industry_type_note.required_if' => __('borrower_validation.business_industry_type_note_required_if'),
            'requested_amount.required' => __('embed_form.requested_amount_required'),
            'currency.required' => __('embed_form.currency_invalid'),
            'currency.in' => __('embed_form.currency_invalid'),
            'additional_funding_types.*.distinct' => __('embed_form.additional_funding_type_invalid'),
            'additional_funding_types.*.different' => __('embed_form.additional_funding_type_preferred'),
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
