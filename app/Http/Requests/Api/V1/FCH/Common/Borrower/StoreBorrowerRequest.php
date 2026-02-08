<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreBorrowerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Personal Info
            'first_name'                => ['required', 'string', 'max:255'],
            'last_name'                 => ['required', 'string', 'max:255'],
            'email'                     => ['required','unique:borrowers,email', 'email', 'max:255'],
            'phone_number'              => ['required', 'string', 'max:20'], // Matching phone_number_max
            'business_website'          => ['nullable', 'url', 'max:255'],
            'business_name'             => ['nullable', 'string', 'max:255'],
            'business_ein'              => ['nullable', 'string', 'max:255'],
            'state_file_number'         => ['nullable', 'string', 'max:255'],
            'duns_number'               => ['nullable', 'string', 'max:255'],
            'business_address'          => ['nullable', 'string', 'max:255'],
            'country'                   => ['required', 'string', 'max:255'],
            'state'                     => ['required', 'string', 'max:255'],
            'city'                      => ['required', 'string', 'max:255'],
            'business_postal_code'      => ['nullable', 'string', 'max:20'], // Matching business_postal_code_max
            'business_phone'            => ['nullable', 'string', 'max:20'], // Matching business_phone_max
            'business_entity_type'      => ['nullable', 'string', 'max:255'],
            'business_industry_type'    => ['nullable', 'string', 'max:255'],
            'business_start_date'       => ['nullable', 'date'],
            'last_4_ssn'                => ['nullable', 'string', 'max:4'],
            'country_code'              => ['nullable', 'string', 'max:5'], // Matching country_code_max
            'plan_id'                   => ['required', 'exists:subscription_plans,id'],
            'notes'                     => ['nullable', 'string'],
           
        ];
    }

    public function messages(): array
    {
        return [
            // Personal Info
            'first_name.required'       => __('borrower_validation.first_name_required'),
            'first_name.string'         => __('borrower_validation.first_name_string'),
            'first_name.max'            => __('borrower_validation.first_name_max'),
            'last_name.required'        => __('borrower_validation.last_name_required'),
            'last_name.string'          => __('borrower_validation.last_name_string'),
            'last_name.max'             => __('borrower_validation.last_name_max'),
            'email.required'            => __('borrower_validation.email_required'),
            'email.unique'              => __('borrower_validation.email_unique'),
            'email.email'               => __('borrower_validation.email_email'),
            'email.max'                 => __('borrower_validation.email_max'),
            'phone_number.required'     => __('borrower_validation.phone_number_required'),
            'phone_number.string'       => __('borrower_validation.phone_number_string'),
            'phone_number.max'          => __('borrower_validation.phone_number_max'),
            
            // Business Info
            'business_website.url'      => __('borrower_validation.business_website_url'),
            'business_website.max'      => __('borrower_validation.business_website_max'),
            'business_name.string'      => __('borrower_validation.business_name_string'),
            'business_name.max'         => __('borrower_validation.business_name_max'),
            'business_ein.string'       => __('borrower_validation.business_ein_string'),
            'business_ein.max'          => __('borrower_validation.business_ein_max'),
            'state_file_number.string'  => __('borrower_validation.state_file_number_string'),
            'state_file_number.max'     => __('borrower_validation.state_file_number_max'),
            'duns_number.string'        => __('borrower_validation.duns_number_string'),
            'duns_number.max'           => __('borrower_validation.duns_number_max'),
            'business_address.string'   => __('borrower_validation.business_address_string'),
            'business_address.max'      => __('borrower_validation.business_address_max'),
            'country.required'          => __('borrower_validation.country_required'),
            'country.string'            => __('borrower_validation.country_string'),
            'country.max'               => __('borrower_validation.country_max'),
            'state.required'            => __('borrower_validation.state_required'),
            'state.string'              => __('borrower_validation.state_string'),
            'state.max'                 => __('borrower_validation.state_max'),
            'city.required'             => __('borrower_validation.city_required'),
            'city.string'               => __('borrower_validation.city_string'),
            'city.max'                  => __('borrower_validation.city_max'),
            'business_postal_code.string' => __('borrower_validation.business_postal_code_string'),
            'business_postal_code.max'  => __('borrower_validation.business_postal_code_max'),
            'business_phone.string'     => __('borrower_validation.business_phone_string'),
            'business_phone.max'        => __('borrower_validation.business_phone_max'),
            'business_entity_type.string' => __('borrower_validation.business_entity_type_string'),
            'business_entity_type.max'  => __('borrower_validation.business_entity_type_max'),
            'business_industry_type.string' => __('borrower_validation.business_industry_type_string'),
            'business_industry_type.max' => __('borrower_validation.business_industry_type_max'),
            'business_start_date.date'  => __('borrower_validation.business_start_date_date'),
            'last_4_ssn.string'         => __('borrower_validation.last_4_ssn_string'),
            'last_4_ssn.max'            => __('borrower_validation.last_4_ssn_max'),
            'country_code.string'       => __('borrower_validation.country_code_string'),
            'country_code.max'          => __('borrower_validation.country_code_max'),
            'plan_id.required'          => __('borrower_validation.plan_id_required'),
            'plan_id.exists'            => __('borrower_validation.plan_id_exists'),
            'notes.string'              => __('borrower_validation.notes_string'),
        ];
    }
}
