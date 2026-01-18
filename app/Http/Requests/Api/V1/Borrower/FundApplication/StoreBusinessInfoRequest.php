<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;

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
            'full_name' => 'nullable|string|max:255',
            'business_name' => 'nullable|string|max:255',
            'business_email' => 'nullable|email|max:255',
            'business_phone_no' => 'nullable|string|max:255',
            'business_country' => 'nullable|string|max:255',
            'business_country_code' => 'nullable|string|max:10',
            'business_address' => 'nullable|string|max:255',
            'business_city' => 'nullable|string|max:255',
            'business_state' => 'nullable|string|max:255',
            'business_postal_code' => 'nullable|string|max:20',
            'industry_type' => 'nullable|string|max:255',
            'entity_type' => 'nullable|string|max:255',
            'years_in_business' => 'nullable|string|max:255',
            'number_of_employees' => 'required|integer',
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
            'full_name.string' => __('borrower_validation.full_name_string'),
            'full_name.max' => __('borrower_validation.full_name_max'),
            'business_name.string' => __('borrower_validation.business_name_string'),
            'business_name.max' => __('borrower_validation.business_name_max'),
            'business_email.email' => __('borrower_validation.business_email_email'),
            'business_email.max' => __('borrower_validation.business_email_max'),
            'business_phone_no.string' => __('borrower_validation.business_phone_no_string'),
            'business_phone_no.max' => __('borrower_validation.business_phone_no_max'),
            'business_country.string' => __('borrower_validation.business_country_string'),
            'business_country.max' => __('borrower_validation.business_country_max'),
            'business_country_code.string' => __('borrower_validation.business_country_code_string'),
            'business_country_code.max' => __('borrower_validation.business_country_code_max'),
            'business_address.string' => __('borrower_validation.business_address_string'),
            'business_address.max' => __('borrower_validation.business_address_max'),
            'business_city.string' => __('borrower_validation.business_city_string'),
            'business_city.max' => __('borrower_validation.business_city_max'),
            'business_state.string' => __('borrower_validation.business_state_string'),
            'business_state.max' => __('borrower_validation.business_state_max'),
            'business_postal_code.string' => __('borrower_validation.business_postal_code_string'),
            'business_postal_code.max' => __('borrower_validation.business_postal_code_max'),
            'industry_type.string' => __('borrower_validation.industry_type_string'),
            'industry_type.max' => __('borrower_validation.industry_type_max'),
            'entity_type.string' => __('borrower_validation.entity_type_string'),
            'entity_type.max' => __('borrower_validation.entity_type_max'),
            'years_in_business.string' => __('borrower_validation.years_in_business_string'),
            'years_in_business.max' => __('borrower_validation.years_in_business_max'),
            'number_of_employees.integer' => __('borrower_validation.number_of_employees_integer'),
        ];
    }
}
