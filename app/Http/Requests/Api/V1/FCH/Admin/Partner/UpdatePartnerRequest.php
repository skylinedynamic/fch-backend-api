<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Partner\Partner;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class UpdatePartnerRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $baseUrl = preg_replace('/^https?:\/\//', '', config('app.base_website'));
        $partner = Partner::find($this->id);
        $wasFreeTrial = $partner ? (bool) $partner->is_free_trail : false;
        $isFreeTrialInput = $this->input('is_free_trail') == 1 || $this->input('is_free_trail') === true || $this->input('is_free_trail') === 'true';

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('partners', 'company_name')->ignore($this->id)->whereNull('deleted_at')
            ],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('partners', 'email')->ignore($this->id)->whereNull('deleted_at'),
                Rule::unique('users', 'email')->where(function ($query) {
                    return $query->where(function ($q) {
                        $q->where('partner_id', '!=', $this->id)
                            ->orWhereNull('partner_id');
                    })->whereNull('deleted_at');
                })
            ],
            'phone_number' => ['sometimes', 'required', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_person_phone_number' => ['nullable', 'string', 'max:50'],
            'contact_person_email' => ['nullable', 'email', 'max:255'],
            'contact_person_relation' => ['nullable', 'string', 'max:255'],
            'partner_type' => ['sometimes', 'required', 'string', 'max:255'],
            'state' => ['sometimes', 'required', 'string', 'max:100'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address_line_1' => ['sometimes', 'required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string'],
            'joining_date' => ['nullable', 'date'],
            'company_logo' => ['nullable'], // 2MB max
            'partner_image' => ['nullable'], // 2MB max
            'assign_to' => ['nullable', 'integer', 'exists:users,id'], // Assigned agent/user     
            'partner_domain' => [
                Rule::requiredIf(function () {
                    $partner = Partner::find($this->id);
                    return $partner && $partner->partner_type === Partner::PARTNER_TYPE_CERTIFIED;
                }),
                'nullable',
                'string',
                'min:4',
                'max:50',
                'regex:/^[a-zA-Z](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/',
                Rule::notIn(Partner::RESTRICTED_DOMAINS),
                function ($attribute, $value, $fail) use ($baseUrl) {
                    $fullDomain = $value . '.' . $baseUrl;
                    if (Partner::where('partner_domain', $fullDomain)->where('id', '!=', $this->id)->whereNull('deleted_at')->exists()) {
                        $fail(__('partner.validation.partner_domain.unique'));
                    }
                },
            ],
            'is_free_trail' => ['nullable', 'boolean'],
            'free_trail_end_date' => [
                Rule::requiredIf(function () use ($isFreeTrialInput) {
                    return $isFreeTrialInput;
                }),
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today'
            ],
            'enrollment_type' => [
                Rule::requiredIf(function () use ($wasFreeTrial, $isFreeTrialInput) {
                    return $wasFreeTrial && !$isFreeTrialInput;
                }),
                'nullable',
                'string',
                'max:255'
            ],
            'enrollment_amount' => [
                Rule::requiredIf(function () use ($wasFreeTrial, $isFreeTrialInput) {
                    return $wasFreeTrial && !$isFreeTrialInput;
                }),
                'nullable',
                'numeric',
                'gt:0',
                'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'
            ],
            'monthly_platform_fee' => [
                Rule::requiredIf(function () use ($wasFreeTrial, $isFreeTrialInput) {
                    return $wasFreeTrial && !$isFreeTrialInput;
                }),
                'nullable',
                'numeric',
                'gt:0',
                'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'
            ],
        ];
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
            'email.unique' => __('partner.validation.email.unique'),
            'phone_number.required' => __('partner.validation.phone_number.required'),
            'partner_type.required' => __('partner.validation.partner_type.required'),
            'state.required' => __('partner.validation.state.required'),
            'city.required' => __('partner.validation.city.required'),
            'country.required' => __('partner.validation.country.required'),
            'address_line_1.required' => __('partner.validation.address_line_1.required'),
            'partner_domain.required' => __('partner.validation.partner_domain.required'),
            'partner_domain.min' => __('partner.validation.partner_domain.min'),
            'partner_domain.max' => __('partner.validation.partner_domain.max'),
            'partner_domain.regex' => __('partner.validation.partner_domain.regex'),
            'partner_domain.unique' => __('partner.validation.partner_domain.unique'),
            'partner_domain.not_in' => __('partner.validation.partner_domain.unique'),
            'free_trail_end_date.required' => 'Trial end date is required when free trial is enabled.',
            'free_trail_end_date.after_or_equal' => 'Trial end date must be today or a future date.',
            'enrollment_type.required' => __('partner.validation.enrollment_type_required'),
            'enrollment_amount.required' => __('partner.validation.enrollment_amount_required'),
            'enrollment_amount.numeric' => __('partner.validation.enrollment_amount_numeric'),
            'enrollment_amount.gt' => __('partner.validation.enrollment_amount_gt'),
            'enrollment_amount.regex' => __('partner.validation.enrollment_amount_regex'),
            'monthly_platform_fee.required' => __('partner.validation.monthly_platform_fee_required'),
            'monthly_platform_fee.numeric' => __('partner.validation.monthly_platform_fee_numeric'),
            'monthly_platform_fee.gt' => __('partner.validation.monthly_platform_fee_gt'),
            'monthly_platform_fee.regex' => __('partner.validation.monthly_platform_fee_regex'),
        ];
    }
}
