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
            'business_ein' => 'nullable|string|max:255',
            'business_phone' => 'nullable|string|max:20',
            'business_address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip_code' => 'required|string|max:20',
            'entity_type' => 'required|string|max:255',
            'business_website' => 'nullable|url|max:255',
            'industry_type' => 'required|string|max:255',
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
            'business_ein.string' => __('borrower_validation.business_ein_string'),
            'business_ein.max' => __('borrower_validation.business_ein_max'),
            'business_phone.string' => __('borrower_validation.business_phone_string'),
            'business_phone.max' => __('borrower_validation.business_phone_max'),
            'business_address.required' => __('borrower_validation.business_address_required'),
            'business_address.string' => __('borrower_validation.business_address_string'),
            'business_address.max' => __('borrower_validation.business_address_max'),
            'city.required' => __('borrower_validation.city_required'),
            'city.string' => __('borrower_validation.city_string'),
            'city.max' => __('borrower_validation.city_max'),
            'state.required' => __('borrower_validation.state_required'),
            'state.string' => __('borrower_validation.state_string'),
            'state.max' => __('borrower_validation.state_max'),
            'zip_code.required' => __('borrower_validation.zip_code_required'),
            'zip_code.string' => __('borrower_validation.zip_code_string'),
            'zip_code.max' => __('borrower_validation.zip_code_max'),
            'entity_type.required' => __('borrower_validation.entity_type_required'),
            'entity_type.string' => __('borrower_validation.entity_type_string'),
            'entity_type.max' => __('borrower_validation.entity_type_max'),
            'business_website.url' => __('borrower_validation.business_website_url'),
            'business_website.max' => __('borrower_validation.business_website_max'),
            'industry_type.required' => __('borrower_validation.industry_type_required'),
            'industry_type.string' => __('borrower_validation.industry_type_string'),
            'industry_type.max' => __('borrower_validation.industry_type_max'),
        ];
    }
}