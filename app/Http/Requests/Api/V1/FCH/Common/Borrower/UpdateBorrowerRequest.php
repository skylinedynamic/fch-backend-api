<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use Illuminate\Validation\Rule;
use App\Rules\BusinessEin;
use App\Rules\DunsNumber;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\Ssn;
use App\Rules\FicoScore;
use App\Rules\PartnerTeamMember;
use App\Rules\BusinessCreditScore;

class UpdateBorrowerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The screen writes an EIN as XX-XXXXXXX; the number is the nine digits,
     * and that is the one form the column holds. Anything that is not a writing
     * of an EIN is left for BusinessEin to refuse.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('business_ein')) {
            $this->merge(['business_ein' => BusinessEin::canonical($this->input('business_ein'))]);
        }
    }

    public function rules(): array
    {
        $borrower = Borrower::find((int) $this->route('id'));
        $wasFreeTrial = $borrower ? (bool) $borrower->is_free_trail : false;
        $hasFreeTrialInput = $this->has('is_free_trail');
        $isFreeTrialInput = $this->input('is_free_trail') == 1
            || $this->input('is_free_trail') === true
            || $this->input('is_free_trail') === 'true';

        // Migrating from free trial to paid?
        $isMigrating = $wasFreeTrial && $hasFreeTrialInput && !$isFreeTrialInput;

        $rules = [
            // ── Personal Info ───────────────────────────────────────────────
            'first_name'   => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name'   => ['nullable', 'string', 'max:255'],
            'last_name'    => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'required', 'string', new UsPhoneNumber()],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                // Matches the users.email index exactly: deleted accounts still hold theirs.
                Rule::unique('users', 'email')
                    ->ignore(
                        User::where('entity_type', Borrower::class)
                            ->where('entity_id', (int) $this->route('id'))
                            ->value('id')
                    ),
            ],
            'country'      => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:255'],
            'state'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'city'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'other_city'   => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'postal_code'  => ['nullable', 'string', new UsPostalCode()],
            'home_address' => ['nullable', 'string', 'max:255'],
            'date_of_birth'=> ['nullable'],
            // Full SSN, digits only or dashed (123456789 / 123-45-6789). Replaces
            // the stored value entirely — omit the field to leave it unchanged.
            'ssn'          => ['nullable', 'string', new Ssn()],
            // ── Business Info ───────────────────────────────────────────────
            'business_name'         => ['nullable', 'string', 'max:255'],
            'business_ein'          => ['nullable', 'string', new BusinessEin()],
            'business_phone'        => ['nullable', 'string', new UsPhoneNumber()],
            'business_address'      => ['nullable', 'string', 'max:255'],
            'business_city'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_country'      => ['sometimes', 'required', 'string', 'max:255'],
            'business_country_code' => ['nullable'],
            'business_other_city'   => ['required_if:business_city,Others', 'nullable', 'string', 'max:255'],
            'business_state'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_postal_code'  => ['nullable', 'string', new UsPostalCode()],
            'business_email'        => ['nullable', 'email', 'max:255'],
            'business_website'      => ['nullable', 'max:255'],
            'business_entity_type'  => ['nullable', 'string', 'max:255'],
            'business_entity_type_note' => ['required_if:business_entity_type,' . config('borrower.entity_type_other'), 'nullable', 'string', 'max:255'],
            'business_industry_type'=> ['nullable', 'string', 'max:255'],
            'business_industry_type_note' => ['required_if:business_industry_type,' . config('borrower.industry_type_other'), 'nullable', 'string', 'max:255'],
            'business_description'  => ['nullable', 'string'],
            'business_logo'         => ['nullable', 'file', 'mimes:jpg,jpeg,png,svg', 'max:5120'],
            'duns_number'           => ['nullable', 'string', new DunsNumber()],
            'state_file_number'     => ['nullable', 'string', 'max:255'],
            'business_start_date'   => ['nullable'],

            // ── Other ───────────────────────────────────────────────────────
            'partner_id'       => ['nullable', 'integer', 'exists:partners,id'],
            'status'           => ['nullable', 'string', 'max:255'],
            'current_tier'     => ['nullable'],
            'assign_id'        => ['nullable', 'integer', 'exists:users,id', new PartnerTeamMember($this->clientPartner($borrower), $borrower?->assign_id, __('borrower_validation.assign_id_not_in_team'))],
            'notes'            => ['nullable', 'string'],
            // The client_info_version the form loaded - a save from an older copy is refused (R2 BUG-065).
            'expected_version' => ['nullable', 'string', 'max:64'],
            'additional_data'  => ['nullable', 'json'],
            'fico_score'       => ['nullable', new FicoScore()],
            'paydex_score'     => ['nullable', new BusinessCreditScore()],
            'is_free_trail'    => ['nullable', 'boolean'],
            'free_trail_end_date' => ['nullable'],

            // ── Enrollment sub-object (only required when migrating off free trial) ──
            'enrollment'       => [$isMigrating ? 'required' : 'nullable', 'array'],
        ];

        // When migrating from free trial → paid, enrollment.plan_id and enrollment.amount are required
        $rules['enrollment.plan_id'] = [
            Rule::requiredIf($isMigrating),
            'nullable',
            'exists:subscription_plans,id',
        ];
        $rules['enrollment.amount'] = [
            Rule::requiredIf($isMigrating),
            'nullable', 'numeric', 'gt:0',
            'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/',
        ];
        $rules['enrollment.enrollment_type'] = ['nullable', 'string', 'max:255'];

        $enrollmentType = strtolower(trim((string) $this->input('enrollment.enrollment_type')));
        if ($enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG) {
            $rules['enrollment.down_payment']      = ['nullable', 'numeric', 'min:0'];
            $rules['enrollment.months']            = ['nullable', 'integer', 'min:1', 'max:24'];
            $rules['enrollment.monthly_amount']    = ['nullable', 'numeric', 'min:0'];
            $rules['enrollment.remaining_balance'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * The partner the client is filed under after this save: its own, or the
     * one the host moves it to.
     */
    private function clientPartner(?Borrower $borrower): ?Partner
    {
        $partnerId = $this->integer('partner_id');

        if ($partnerId && $this->user()?->partner?->is_host) {
            return Partner::find($partnerId);
        }

        return $borrower?->partner ?? $this->user()?->partner;
    }

    public function messages(): array
    {
        return [
            'first_name.required'            => __('borrower_validation.first_name_required'),
            'last_name.required'             => __('borrower_validation.last_name_required'),
            'email.required'                 => __('borrower_validation.email_required'),
            'email.email'                    => __('borrower_validation.email_email'),
            'email.unique'                   => __('borrower_validation.email_unique'),
            'phone_number.required'          => __('borrower_validation.phone_number_required'),
            'phone_number.regex'             => __('borrower_validation.phone_number_regex'),
            'country.required'               => __('borrower_validation.country_required'),
            'state.required'                 => __('borrower_validation.state_required'),
            'city.required_without'          => __('borrower_validation.city_required'),
            'business_country.required'      => __('borrower_validation.business_country_required'),
            'business_state.required'        => __('borrower_validation.business_state_required'),
            'business_city.required_without' => __('borrower_validation.business_city_required'),
            'other_city.required_if'         => __('borrower_validation.other_city_required'),
            'business_other_city.required_if'=> __('borrower_validation.business_other_city_required'),
            'business_email.email'           => __('borrower_validation.business_email_email'),
            'business_website.url'           => __('borrower_validation.business_website_url'),
            'business_logo.mimes'            => __('borrower_validation.business_logo_mimes'),
            'business_logo.max'              => __('borrower_validation.business_logo_max'),
            'business_phone.regex'           => __('borrower_validation.business_phone_regex'),
            'ssn.regex'                      => 'Please enter a valid 9-digit Social Security Number.',
            'fico_score.numeric'             => __('borrower_validation.fico_score_numeric'),
            'paydex_score.numeric'           => __('borrower_validation.paydex_score_numeric'),
            'assign_id.exists'               => __('borrower_validation.assign_id_exists'),
            'free_trail_end_date.required'   => 'Trial end date is required when free trial is enabled.',
            'enrollment.required'            => 'Enrollment details are required when converting from free trial.',
            'enrollment.array'               => 'Enrollment must be an object.',
            'enrollment.plan_id.required'    => __('borrower_validation.plan_id_required'),
            'enrollment.plan_id.exists'      => __('borrower_validation.plan_id_exists'),
            'enrollment.amount.required'     => __('borrower_validation.enrollment_payment_required'),
            'enrollment.amount.numeric'      => __('borrower_validation.enrollment_payment_numeric'),
            'enrollment.amount.gt'           => __('borrower_validation.enrollment_payment_gt'),
            'enrollment.amount.regex'        => __('borrower_validation.enrollment_payment_regex'),
            'business_entity_type_note.required_if' => __('borrower_validation.business_entity_type_note_required_if'),
            'business_industry_type_note.required_if' => __('borrower_validation.business_industry_type_note_required_if'),
        ];
    }
}
