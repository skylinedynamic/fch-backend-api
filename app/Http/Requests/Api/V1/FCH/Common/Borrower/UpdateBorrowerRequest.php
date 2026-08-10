<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\Borrower;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use Illuminate\Validation\Rule;

class UpdateBorrowerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
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
            'last_name'    => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->ignore(
                        User::where('entity_type', Borrower::class)
                            ->where('entity_id', (int) $this->route('id'))
                            ->value('id')
                    )
                    ->whereNull('deleted_at'),
            ],
            'country'      => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:255'],
            'state'        => ['nullable', 'string', 'max:255'],
            'city'         => ['sometimes', 'required_without:other_city', 'string', 'max:255'],
            'other_city'   => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'postal_code'  => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'date_of_birth'=> ['nullable'],
            'last_4_ssn'   => ['nullable', 'string', 'max:4'],

            // ── Business Info ───────────────────────────────────────────────
            'business_name'         => ['nullable', 'string', 'max:255'],
            'business_ein'          => ['nullable', 'string', 'max:255'],
            'business_phone'        => ['nullable', 'string', 'max:255', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'business_address'      => ['nullable', 'string', 'max:255'],
            'business_city'         => ['sometimes', 'required_without:business_other_city', 'string', 'max:255'],
            'business_country'      => ['sometimes', 'required', 'string', 'max:255'],
            'business_country_code' => ['nullable'],
            'business_other_city'   => ['required_if:business_city,Others', 'nullable', 'string', 'max:255'],
            'business_state'        => ['sometimes', 'required', 'string', 'max:255'],
            'business_postal_code'  => ['nullable', 'string', 'max:255'],
            'business_email'        => ['nullable', 'email', 'max:255'],
            'business_website'      => ['nullable', 'max:255'],
            'business_entity_type'  => ['nullable', 'string', 'max:255'],
            'business_industry_type'=> ['nullable', 'string', 'max:255'],
            'business_description'  => ['nullable', 'string'],
            'business_logo'         => ['nullable', 'file', 'mimes:jpg,jpeg,png,svg', 'max:5120'],
            'duns_number'           => ['nullable', 'string', 'max:255'],
            'state_file_number'     => ['nullable', 'string', 'max:255'],
            'business_start_date'   => ['nullable'],

            // ── Other ───────────────────────────────────────────────────────
            'partner_id'       => ['nullable', 'integer', 'exists:partners,id'],
            'status'           => ['nullable', 'string', 'max:255'],
            'current_tier'     => ['nullable'],
            'assign_id'        => ['nullable', 'integer', 'exists:users,id'],
            'notes'            => ['nullable', 'string'],
            'additional_data'  => ['nullable', 'json'],
            'fico_score'       => ['nullable', 'numeric'],
            'paydex_score'     => ['nullable', 'numeric'],
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
        if ($enrollmentType === SubscriptionPlan::INSTALLMENT_PLAN) {
            $rules['enrollment.down_payment']      = ['nullable', 'numeric', 'min:0'];
            $rules['enrollment.months']            = ['nullable', 'integer', 'min:1', 'max:24'];
            $rules['enrollment.monthly_amount']    = ['nullable', 'numeric', 'min:0'];
            $rules['enrollment.remaining_balance'] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
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
        ];
    }
}
