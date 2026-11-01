<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Validation\Rule;
use App\Rules\BusinessEin;
use App\Rules\DunsNumber;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\Ssn;
use App\Rules\FicoScore;
use App\Rules\PartnerTeamMember;
use App\Rules\BusinessCreditScore;

class StoreBorrowerRequest extends BaseApiRequest
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
        $isFreeTrial = $this->input('is_free_trail') == 1
            || $this->input('is_free_trail') === true
            || $this->input('is_free_trail') === 'true';

        $rules = [
            // ── Personal Info ───────────────────────────────────────────────
            'first_name'           => ['required', 'string', 'max:255'],
            'middle_name'           => ['nullable', 'string', 'max:255'],
            'last_name'            => ['required', 'string', 'max:255'],
            'email'                => [
                'required',
                'email',
                'max:255',
                Rule::unique('borrowers', 'email')->whereNull('deleted_at'),
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'phone_number'         => ['required', 'string', new UsPhoneNumber()],
            'country'              => ['required', 'string', 'max:255'],
            'state'                => ['nullable', 'string', 'max:255'],
            'city'                 => ['nullable', 'string', 'max:255'],
            'other_city'           => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'country_code'         => ['nullable', 'string', 'max:5'],
            'postal_code'          => ['nullable', 'string', new UsPostalCode()],
            'home_address'         => ['nullable', 'string', 'max:255'],
            'date_of_birth'        => ['nullable'],
            'ssn'                  => ['nullable', 'string', new Ssn()],
            // ── Business Info ───────────────────────────────────────────────
            'business_name'        => ['nullable', 'string', 'max:255'],
            'business_ein'         => ['nullable', 'string', new BusinessEin()],
            'business_website'     => ['nullable', 'max:255'],
            'business_address'     => ['nullable', 'string', 'max:255'],
            'business_country'     => ['required', 'string', 'max:255'],
            'business_country_code'=> ['nullable', 'string', 'max:5'],
            'business_state'       => ['nullable', 'string', 'max:255'],
            'business_city'        => ['nullable', 'string', 'max:255'],
            'business_other_city'  => ['required_if:business_city,Others', 'nullable', 'string', 'max:255'],
            'business_postal_code' => ['nullable', 'string', new UsPostalCode()],
            'business_phone'       => ['nullable', 'string', new UsPhoneNumber()],
            'business_entity_type' => ['nullable', 'string', 'max:255'],
            'business_entity_type_note' => ['required_if:business_entity_type,' . config('borrower.entity_type_other'), 'nullable', 'string', 'max:255'],
            'business_industry_type' => ['nullable', 'string', 'max:255'],
            'business_industry_type_note' => ['required_if:business_industry_type,' . config('borrower.industry_type_other'), 'nullable', 'string', 'max:255'],
            'business_start_date'  => ['nullable'],
            'duns_number'          => ['nullable', 'string', new DunsNumber()],
            'state_file_number'    => ['nullable', 'string', 'max:255'],

            // ── Other ───────────────────────────────────────────────────────
            'notes'          => ['nullable'],
            'fico_score'     => ['nullable', new FicoScore()],
            'paydex_score'   => ['nullable', new BusinessCreditScore()],
            'is_free_trail'  => ['nullable', 'boolean'],
            'assign_id'      => ['nullable', 'integer', 'exists:users,id', new PartnerTeamMember($this->clientPartner(), null, __('borrower_validation.assign_id_not_in_team'))],
            'partner_id'     => ['nullable', 'integer', 'exists:partners,id'],
            'free_trail_end_date' => ['nullable'],

            // ── Enrollment sub-object ───────────────────────────────────────
            'enrollment'     => [$isFreeTrial ? 'nullable' : 'required', 'array'],
        ];

        if ($isFreeTrial) {
            $rules['enrollment.plan_id'] = ['nullable', 'exists:subscription_plans,id'];
            $rules['enrollment.amount']  = ['nullable'];
        } else {
            $rules['enrollment.plan_id']        = ['required', 'exists:subscription_plans,id'];
            $rules['enrollment.amount']         = ['required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'];
            $rules['enrollment.enrollment_type']= ['nullable', 'string', 'max:255'];

            $enrollmentType = strtolower(trim((string) $this->input('enrollment.enrollment_type')));
            if ($enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG) {
                $rules['enrollment.down_payment']      = ['nullable', 'numeric', 'min:0'];
                $rules['enrollment.months']            = ['nullable', 'integer', 'min:1', 'max:24'];
                $rules['enrollment.monthly_amount']    = ['nullable', 'numeric', 'min:0'];
                $rules['enrollment.remaining_balance'] = ['nullable', 'numeric', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * The partner the new client is filed under, as BorrowerService::store()
     * decides it: the host may name a partner, anyone else gets their own.
     */
    private function clientPartner(): ?Partner
    {
        $user = $this->user();
        $partnerId = $this->integer('partner_id');

        return $partnerId && $user?->partner?->is_host
            ? Partner::find($partnerId)
            : $user?->partner;
    }

    public function messages(): array
    {
        return [
            'first_name.required'            => __('borrower_validation.first_name_required'),
            'first_name.string'              => __('borrower_validation.first_name_string'),
            'first_name.max'                 => __('borrower_validation.first_name_max'),
            'last_name.required'             => __('borrower_validation.last_name_required'),
            'last_name.string'               => __('borrower_validation.last_name_string'),
            'last_name.max'                  => __('borrower_validation.last_name_max'),
            'email.required'                 => __('borrower_validation.email_required'),
            'email.unique'                   => __('borrower_validation.email_unique'),
            'email.email'                    => __('borrower_validation.email_email'),
            'email.max'                      => __('borrower_validation.email_max'),
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
            'business_entity_type_note.required_if' => __('borrower_validation.business_entity_type_note_required_if'),
            'business_industry_type.string' => __('borrower_validation.business_industry_type_string'),
            'business_industry_type.max' => __('borrower_validation.business_industry_type_max'),
            'business_industry_type_note.required_if' => __('borrower_validation.business_industry_type_note_required_if'),
            'business_phone.regex'           => __('borrower_validation.business_phone_regex'),
            'fico_score.numeric'             => __('borrower_validation.fico_score_numeric'),
            'paydex_score.numeric'           => __('borrower_validation.paydex_score_numeric'),
            'assign_id.exists'               => __('borrower_validation.assign_id_exists'),
            'free_trail_end_date.required'   => 'Trial end date is required when free trial is enabled.',
            'enrollment.required'            => 'Enrollment details are required.',
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
