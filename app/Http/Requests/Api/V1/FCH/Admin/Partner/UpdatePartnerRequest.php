<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Api\V1\BaseApiRequest;

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
            'enrollment_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_platform_fee' => ['nullable', 'numeric', 'min:0'],
            'partner_domain' => ['nullable', 'string', 'max:255'],
            'borrower_domain' => ['nullable', 'string', 'max:255'],
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
        ];
    }
}
