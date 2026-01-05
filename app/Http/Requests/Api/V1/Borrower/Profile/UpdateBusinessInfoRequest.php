<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessInfoRequest extends BaseApiRequest
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
            'business_name' => 'required|string|max:255',
            'business_ein' => 'required|string|max:255',
            'business_phone' => 'nullable|string|max:20',
            'business_email' => 'required|email|max:255',
            'duns_number' => 'nullable|string|max:255',
            'business_address' => 'required|string|max:255',
            'business_city' => 'required|string|max:255',
            'business_state' => 'required|string|max:255',
            'business_zip_code' => 'required|string|max:20',
            'business_website' => 'nullable|max:255',
            'business_entity_type' => 'required|string|max:255',
            'business_industry_type' => 'required|string|max:255',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => __('borrower_validation.business_name_required'),
            'business_name.string' => __('borrower_validation.business_name_string'),
            'business_name.max' => __('borrower_validation.business_name_max'),
            'business_ein.required' => __('borrower_validation.business_ein_required'),
            'business_ein.string' => __('borrower_validation.business_ein_string'),
            'business_ein.max' => __('borrower_validation.business_ein_max'),
            'business_phone.string' => __('borrower_validation.business_phone_string'),
            'business_phone.max' => __('borrower_validation.business_phone_max'),
            'business_address.required' => __('borrower_validation.business_address_required'),
            'business_address.string' => __('borrower_validation.business_address_string'),
            'business_address.max' => __('borrower_validation.business_address_max'),
            'business_city.required' => __('borrower_validation.business_city_required'),
            'business_email.required' => __('borrower_validation.business_email_required'),
            'business_email.email' => __('borrower_validation.business_email_email'),
            'business_email.max' => __('borrower_validation.business_email_max'),
            'duns_number.string' => __('borrower_validation.duns_number_string'),
            'duns_number.max' => __('borrower_validation.duns_number_max'),
            'business_city.string' => __('borrower_validation.business_city_string'),
            'business_city.max' => __('borrower_validation.business_city_max'),
            'business_state.required' => __('borrower_validation.business_state_required'),
            'business_state.string' => __('borrower_validation.business_state_string'),
            'business_state.max' => __('borrower_validation.business_state_max'),
            'business_zip_code.required' => __('borrower_validation.business_zip_code_required'),
            'business_zip_code.string' => __('borrower_validation.business_zip_code_string'),
            'business_zip_code.max' => __('borrower_validation.business_zip_code_max'),
            'business_entity_type.required' => __('borrower_validation.business_entity_type_required'),
            'business_entity_type.string' => __('borrower_validation.business_entity_type_string'),
            'business_entity_type.max' => __('borrower_validation.business_entity_type_max'),
            'business_website.url' => __('borrower_validation.business_website_url'),
            'business_website.max' => __('borrower_validation.business_website_max'),
            'business_industry_type.required' => __('borrower_validation.business_industry_type_required'),
            'business_industry_type.string' => __('borrower_validation.business_industry_type_string'),
            'business_industry_type.max' => __('borrower_validation.business_industry_type_max'),
        ];
    }
}