<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

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
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'full_name'                   => ['required', 'string', 'max:255'],
            'company_name'                => ['required', 'string', 'max:255'],
            'email'                       => ['required', 'email', 'max:255', 'unique:partners,email', 'unique:users,email'],
            'phone_number'                => ['required', 'string', 'max:50'],
            'status'                      => ['nullable', 'string', 'max:255'],
            'contact_person'              => ['nullable', 'string', 'max:255'],
            'contact_person_phone_number' => ['nullable', 'string', 'max:50'],
            'contact_person_email'        => ['nullable', 'email', 'max:255'],
            'contact_person_relation'     => ['nullable', 'string', 'max:255'],
            'partner_type'                => ['required', 'string', 'max:255'],
            'state'                       => ['required', 'string', 'max:100'],
            'city'                        => ['required', 'string', 'max:100'],
            'country'                     => ['required', 'string', 'max:100'],
            'zip_code'                    => ['nullable', 'string', 'max:20'],
            'address_line_1'              => ['required', 'string', 'max:255'],
            'address_line_2'              => ['nullable', 'string', 'max:255'],
            'tax_id'                      => ['nullable', 'string', 'max:100'],
            'website'                     => ['nullable', 'string'],
            'joining_date'                => ['nullable', 'date'],
            'company_logo'                => ['nullable'], // 2MB max
            'partner_image'               => ['nullable'], // 2MB max
            'assign_to'                   => ['nullable', 'integer', 'exists:users,id'], // Assigned agent/user
            'enrollment_type'             => ['required', 'string', 'max:255'],
            'enrollment_amount'           => ['required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'],
            'monthly_platform_fee'        => ['required', 'numeric', 'gt:0', 'regex:/^[1-9]\d*(\.\d+)?$|^0\.\d*[1-9]\d*$/'],
            'total_free_users'            => ['nullable', 'integer', 'min:0'],
            'total_paid_users'            => ['nullable', 'integer', 'min:0'],
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
            'phone_number.required' => __('partner.validation.phone_number.required'),
            'partner_type.required' => __('partner.validation.partner_type.required'),
            'state.required' => __('partner.validation.state.required'),
            'city.required' => __('partner.validation.city.required'),
            'country.required' => __('partner.validation.country.required'),
            'address_line_1.required' => __('partner.validation.address_line_1.required'),
            'enrollment_type.required' => __('partner.validation.enrollment_type_required'),
            'enrollment_amount.required' => __('partner.validation.enrollment_amount_required'),
            'enrollment_amount.numeric' => __('partner.validation.enrollment_amount_numeric'),
            'enrollment_amount.gt' => __('partner.validation.enrollment_amount_gt'),
            'enrollment_amount.regex'   => __('partner.validation.enrollment_amount_regex'),
            'monthly_platform_fee.required' => __('partner.validation.monthly_platform_fee_required'),
            'monthly_platform_fee.numeric' => __('partner.validation.monthly_platform_fee_numeric'),
            'monthly_platform_fee.gt' => __('partner.validation.monthly_platform_fee_gt'),
            'monthly_platform_fee.regex'   => __('partner.validation.monthly_platform_fee_regex'),
        ];
    }
}
