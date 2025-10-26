<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePartnerRequest extends FormRequest
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
            'partner_name'   => ['required', 'string', 'max:255'],
            'company_name'   => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255'],
            'phone_number'   => ['required', 'string', 'max:50'],
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
            'partner_name.required' => 'Partner name is required.',
            'company_name.required' => 'Company name is required.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'phone_number.required' => 'Phone number is required.',
            'website.url' => 'Please provide a valid website URL.',
        ];
    }
}