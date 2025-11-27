<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Partner;

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
            'full_name'                   => ['sometimes', 'required', 'string', 'max:255'],
            'company_name'                => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('partners', 'email')->ignore($this->id)
            ],
            'phone_number'                => ['sometimes', 'required', 'string', 'max:50'],
            'status'                      => ['nullable', 'string', 'max:255'],
            'contact_person'              => ['nullable', 'string', 'max:255'],
            'contact_person_phone_number' => ['nullable', 'string', 'max:50'],
            'contact_person_email'        => ['nullable', 'email', 'max:255'],
            'contact_person_relation'     => ['nullable', 'string', 'max:255'],
            'partner_type'                => ['nullable', 'string', 'max:255'],
            'state'                       => ['nullable', 'string', 'max:100'],
            'city'                        => ['nullable', 'string', 'max:100'],
            'country'                     => ['nullable', 'string', 'max:100'],
            'zip_code'                    => ['nullable', 'string', 'max:20'],
            'address_line_1'              => ['nullable', 'string', 'max:255'],
            'address_line_2'              => ['nullable', 'string', 'max:255'],
            'tax_id'                      => ['nullable', 'string', 'max:100'],
            'website'                     => ['nullable', 'string'],
            'joining_date'                => ['nullable', 'date'],
            'company_logo'                => ['nullable'], // 2MB max
            'partner_image'               => ['nullable'], // 2MB max
            'assign_to'                   => ['nullable', 'integer', 'exists:users,id'], // Assigned agent/user     
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
            'phone_number.required' => __('partner.validation.phone_number.required')
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
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0]; 
        }
        throw new HttpResponseException(
            ApiResponse::validationError($errors, __('auth.validation_failed'))
        );
    }
}