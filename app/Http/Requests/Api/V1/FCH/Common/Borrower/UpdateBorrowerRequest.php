<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\BaseApiRequest;

class UpdateBorrowerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Personal Info
            'first_name'                => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'                 => ['sometimes', 'required', 'string', 'max:255'],
            'email'                     => ['sometimes', 'required', 'email', 'max:255'],
            'phone_number'              => ['nullable', 'string', 'max:255'],
            'country'                   => ['nullable', 'string', 'max:255'],
            'country_code'              => ['nullable', 'string', 'max:255'],
            'state'                     => ['nullable', 'string', 'max:255'],
            'city'                      => ['nullable', 'string', 'max:255'],
            'postal_code'               => ['nullable', 'string', 'max:255'],
            'home_address'              => ['nullable', 'string', 'max:255'],
            'home_phone'                => ['nullable', 'string', 'max:255'],
            'date_of_birth'             => ['nullable', 'date'],
            'last_4_ssn'                => ['nullable', 'string', 'max:4'],

            // Business Info
            'business_name'             => ['nullable', 'string', 'max:255'],
            'business_ein'              => ['nullable', 'string', 'max:255'],
            'business_phone'            => ['nullable', 'string', 'max:255'],
            'business_address'          => ['nullable', 'string', 'max:255'],
            'business_city'             => ['nullable', 'string', 'max:255'],
            'business_state'            => ['nullable', 'string', 'max:255'],
            'business_postal_code'      => ['nullable', 'string', 'max:255'],
            'business_email'            => ['nullable', 'email', 'max:255'],
            'business_website'          => ['nullable', 'url', 'max:255'],
            'business_entity_type'      => ['nullable', 'string', 'max:255'],
            'business_industry_type'    => ['nullable', 'string', 'max:255'],
            'business_description'      => ['nullable', 'string'],
            'business_logo'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,svg', 'max:5120'],
            'business_registration_number' => ['nullable', 'string', 'max:255'],
            'tax_identification_number' => ['nullable', 'string', 'max:255'],
            'duns_number'               => ['nullable', 'string', 'max:255'],
            'state_file_number'         => ['nullable', 'string', 'max:255'],
            'business_start_date'       => ['nullable', 'date'],

            // Financial Info
            'currency'                  => ['nullable', 'string', 'max:255'],
            'years_in_business'         => ['nullable', 'string', 'max:255'],
            'monthly_revenue'           => ['nullable', 'numeric', 'min:0'],
            'annual_revenue'            => ['nullable', 'numeric', 'min:0'],

            // Other
            'partner_id'                => ['nullable', 'integer', 'exists:partners,id'],
            'status'                    => ['nullable', 'string', 'max:255'],
            'current_tier'              => ['nullable', 'string', 'max:255'],
            'assign_id'                 => ['nullable', 'integer', 'exists:users,id'],
            'notes'                     => ['nullable', 'string'],
            'additional_data'           => ['nullable', 'json'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'       => __('borrower.validation.first_name.required'),
            'last_name.required'        => __('borrower.validation.last_name.required'),
            'email.required'            => __('borrower.validation.email.required'),
            'email.email'               => __('borrower.validation.email.email'),
            'business_email.email'      => __('borrower.validation.business_email.email'),
            'business_website.url'      => __('borrower.validation.business_website.url'),
            'business_logo.mimes'       => __('borrower.validation.business_logo.mimes'),
            'business_logo.max'         => __('borrower.validation.business_logo.max'),
            'monthly_revenue.numeric'   => __('borrower.validation.monthly_revenue.numeric'),
            'annual_revenue.numeric'    => __('borrower.validation.annual_revenue.numeric'),
            'partner_id.exists'         => __('borrower.validation.partner_id.exists'),
            'assign_id.exists'          => __('borrower.validation.assign_id.exists'),
        ];
    }
}
