<?php

namespace App\Http\Requests\Api\V1\FCH\Partner\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPostalCode;

class StoreBusinessInfoRequest extends BaseApiRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => 'nullable|exists:funding_applications,id',
            'funding_request_id' => 'nullable',
            'funding_sequence_id' => 'nullable',
            'full_name' => 'required|string|max:255',
            'business_name' => 'required|string|max:255',
            'business_email' => 'required|email|max:255',
            'business_phone_no' => 'required|string|max:255',
            'business_country' => 'required|string|max:255',
            'business_country_code' => 'required|string|max:10',
            'business_address' => 'required|string|max:255',
            'business_city' => 'required|string|max:255',
            'business_state' => 'required|string|max:255',
            'business_postal_code' => ['nullable', 'string', new UsPostalCode()],
            'industry_type' => 'required|string|max:255',
            'entity_type' => 'required|string|max:255',
            'years_in_business' => 'required|string|max:255',
            'number_of_employees' => 'nullable|integer',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.exists' => __('borrower_validation.funding_application_id_exists'),
            
            'full_name.required' => __('borrower_validation.full_name_required'),
            'full_name.string' => __('borrower_validation.full_name_string'),
            'full_name.max' => __('borrower_validation.full_name_max'),
            
            'business_name.required' => __('borrower_validation.business_name_required'),
            'business_name.string' => __('borrower_validation.business_name_string'),
            'business_name.max' => __('borrower_validation.business_name_max'),
            
            'business_email.required' => __('borrower_validation.business_email_required'),
            'business_email.email' => __('borrower_validation.business_email_email'),
            'business_email.max' => __('borrower_validation.business_email_max'),
            
            'business_phone_no.required' => __('borrower_validation.business_phone_no_required'),
            'business_phone_no.string' => __('borrower_validation.business_phone_no_string'),
            'business_phone_no.max' => __('borrower_validation.business_phone_no_max'),
            
            'business_country.required' => __('borrower_validation.business_country_required'),
            'business_country.string' => __('borrower_validation.business_country_string'),
            'business_country.max' => __('borrower_validation.business_country_max'),
            
            'business_country_code.required' => __('borrower_validation.business_country_code_required'),
            'business_country_code.string' => __('borrower_validation.business_country_code_string'),
            'business_country_code.max' => __('borrower_validation.business_country_code_max'),
            
            'business_address.required' => __('borrower_validation.business_address_required'),
            'business_address.string' => __('borrower_validation.business_address_string'),
            'business_address.max' => __('borrower_validation.business_address_max'),
            
            'business_city.required' => __('borrower_validation.business_city_required'),
            'business_city.string' => __('borrower_validation.business_city_string'),
            'business_city.max' => __('borrower_validation.business_city_max'),
            
            'business_state.required' => __('borrower_validation.business_state_required'),
            'business_state.string' => __('borrower_validation.business_state_string'),
            'business_state.max' => __('borrower_validation.business_state_max'),
            
            'business_postal_code.string' => __('borrower_validation.business_postal_code_string'),
            
            'industry_type.required' => __('borrower_validation.industry_type_required'),
            'industry_type.string' => __('borrower_validation.industry_type_string'),
            'industry_type.max' => __('borrower_validation.industry_type_max'),
            
            'entity_type.required' => __('borrower_validation.entity_type_required'),
            'entity_type.string' => __('borrower_validation.entity_type_string'),
            'entity_type.max' => __('borrower_validation.entity_type_max'),
            
            'years_in_business.required' => __('borrower_validation.years_in_business_required'),
            'years_in_business.string' => __('borrower_validation.years_in_business_string'),
            'years_in_business.max' => __('borrower_validation.years_in_business_max'),
            
            'number_of_employees.integer' => __('borrower_validation.number_of_employees_integer'),
        ];
    }
}

