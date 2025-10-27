<?php

namespace App\Http\Requests\Api\V1\Admin\Partner;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;
use App\Helpers\ApiResponse;

use Illuminate\Validation\Rule;


class UpdatePartnerRequest extends FormRequest
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
            'partner_name'   => ['sometimes', 'required', 'string', 'max:255'],
            'company_name'   => ['sometimes', 'required', 'string', 'max:255'],
            'email'          => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user)],
            'phone_number'   => ['sometimes', 'required', 'string', 'max:50'],
            // Optional fields
            'state'          => ['nullable', 'string', 'max:100'],
            'city'           => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            'zip_code'       => ['nullable', 'string', 'max:20'],
            'tax_id'         => ['nullable', 'string', 'max:100'],
            'website'        => ['nullable', 'url'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'partner_name.required' => __('partner.validation.partner_name.required'),
            'company_name.required' => __('partner.validation.company_name.required'),
            'email.required' => __('partner.validation.email.required'),
            'email.email' => __('partner.validation.email.email'),
            'phone_number.required' => __('partner.validation.phone_number.required'),
            'website.url' => __('partner.validation.website.url'),
        ];
    }

            /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            ApiResponse::validationError($validator->errors(), __('auth.validation_failed'))
        );
    }
}