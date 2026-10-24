<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\BusinessEin;

class StorePartnerRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A Tax ID is an EIN: the screen writes it XX-XXXXXXX, the column keeps the
     * nine digits. Anything that is not a writing of an EIN is left for
     * BusinessEin to refuse.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('tax_id')) {
            $this->merge(['tax_id' => BusinessEin::canonical($this->input('tax_id'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));

        $isFreeTrial = $this->input('is_free_trail') == 1
            || $this->input('is_free_trail') === true
            || $this->input('is_free_trail') === 'true';

        $rules = [
            // ── Partner Info ────────────────────────────────────────────────
            'full_name' => ['required', 'string', 'max:255'],
            'company_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('partners', 'company_name')->whereNull('deleted_at'),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('partners', 'email')->whereNull('deleted_at'),
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'phone_number' => ['required', 'string', new UsPhoneNumber()],
            'status' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_person_phone_number' => ['nullable', 'string', new UsPhoneNumber()],
            'contact_person_email' => ['nullable', 'email', 'max:255'],
            'contact_person_relation' => ['nullable', 'string', 'max:255'],
            'partner_type' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', new UsPostalCode()],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', new BusinessEin()],
            'website' => ['nullable', 'string'],
            'joining_date' => ['nullable', 'date'],
            'company_logo' => ['nullable'],
            'partner_image' => ['nullable'],
            'assign_to' => ['nullable', 'integer', 'exists:users,id'],
            'partner_domain' => [
                'required',
                'string',
                'min:4',
                'max:50',
                'regex:/^[a-zA-Z](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/',
                Rule::notIn(Partner::RESTRICTED_DOMAINS),
                function ($attribute, $value, $fail) use ($baseUrl) {
                    $fullDomain = $value . '.' . $baseUrl;
                    if (Partner::where('partner_domain', $fullDomain)->whereNull('deleted_at')->exists()) {
                        $fail(__('partner.validation.partner_domain.unique'));
                    }
                },
            ],
            'borrower_domain' => ['nullable', 'string', 'max:255'],
            'is_free_trail' => ['nullable', 'boolean'],
            'free_trail_end_date' => [$isFreeTrial ? 'required' : 'nullable', 'date_format:Y-m-d', $isFreeTrial ? 'after_or_equal:today' : 'nullable'],

            // ── Enrollment sub-object ───────────────────────────────────────
            'enrollment' => [$isFreeTrial ? 'nullable' : 'required', 'array'],
        ];

        if ($isFreeTrial) {
            $rules['enrollment.enrollment_type'] = ['nullable', 'string', 'max:255'];
            $rules['enrollment.amount'] = ['nullable'];
            $rules['enrollment.monthly_platform_fee'] = ['nullable'];
        } else {
            $rules['enrollment.plan_id'] = ['nullable', 'exists:subscription_plans,id'];
            $rules['enrollment.enrollment_type'] = ['required', 'string', 'max:255'];
            $rules['enrollment.amount'] = ['required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'];
            $rules['enrollment.monthly_platform_fee'] = ['required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'];

            $enrollmentType = strtolower(trim((string) $this->input('enrollment.enrollment_type')));

            if ($enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG) {
                $rules['enrollment.down_payment'] = ['nullable', 'numeric', 'min:0'];
                $rules['enrollment.months'] = ['nullable', 'integer', 'min:1', 'max:24'];
                $rules['enrollment.monthly_amount'] = ['nullable', 'numeric', 'min:0'];
                $rules['enrollment.remaining_balance'] = ['nullable', 'numeric', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'full_name.required' => __('partner.validation.full_name.required'),
            'company_name.required' => __('partner.validation.company_name.required'),
            'email.required' => __('partner.validation.email.required'),
            'email.email' => __('partner.validation.email.email'),
            'phone_number.required' => __('partner.validation.phone_number.required'),
            'partner_type.required' => __('partner.validation.partner_type.required'),
            'state.required' => __('partner.validation.state.required'),
            'city.required' => __('partner.validation.city.required'),
            'country.required' => __('partner.validation.country.required'),
            'address_line_1.required' => __('partner.validation.address_line_1.required'),
            'free_trail_end_date.required' => 'Trial end date is required when free trial is enabled.',
            'free_trail_end_date.after_or_equal' => 'Trial end date must be today or a future date.',
            'partner_domain.required' => __('partner.validation.partner_domain.required'),
            'partner_domain.min' => __('partner.validation.partner_domain.min'),
            'partner_domain.max' => __('partner.validation.partner_domain.max'),
            'partner_domain.regex' => __('partner.validation.partner_domain.regex'),
            'partner_domain.unique' => __('partner.validation.partner_domain.unique'),
            'enrollment.required' => 'Enrollment details are required.',
            'enrollment.array' => 'Enrollment must be an object.',
            'enrollment.enrollment_type.required' => __('partner.validation.enrollment_type_required'),
            'enrollment.amount.required' => __('partner.validation.enrollment_amount_required'),
            'enrollment.amount.numeric' => __('partner.validation.enrollment_amount_numeric'),
            'enrollment.amount.gt' => __('partner.validation.enrollment_amount_gt'),
            'enrollment.amount.regex' => __('partner.validation.enrollment_amount_regex'),
            'enrollment.monthly_platform_fee.required' => __('partner.validation.monthly_platform_fee_required'),
            'enrollment.monthly_platform_fee.numeric' => __('partner.validation.monthly_platform_fee_numeric'),
            'enrollment.monthly_platform_fee.gt' => __('partner.validation.monthly_platform_fee_gt'),
            'enrollment.monthly_platform_fee.regex' => __('partner.validation.monthly_platform_fee_regex'),
        ];
    }
}
