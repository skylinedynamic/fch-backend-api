<?php

namespace App\Http\Requests\Api\V1\FCH\Partner\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPostalCode;

class StoreOwnershipInfoRequest extends BaseApiRequest
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
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'ssn' => 'required|string|digits:9',
            'country' => 'nullable|string|max:255',
            'phone_number' => 'required|string|max:255',
            'citizenship_status' => 'required|string|max:255',
            'street_address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip_code' => ['nullable', 'string', new UsPostalCode()],
            'ownership' => 'required|numeric',
            'has_co_borrower' => 'nullable|boolean',
            'co_borrowers' => 'nullable|array',
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
            'first_name.string' => __('borrower_validation.first_name_string'),
            'first_name.max' => __('borrower_validation.first_name_max'),
            'last_name.string' => __('borrower_validation.last_name_string'),
            'last_name.max' => __('borrower_validation.last_name_max'),
            'date_of_birth.date' => __('borrower_validation.date_of_birth_date'),
            'ssn.string' => __('borrower_validation.ssn_string'), 
            'ssn.digits' => __('borrower_validation.ssn_digits'),
            'country.string' => __('borrower_validation.country_string'),
            'country.max' => __('borrower_validation.country_max'),
            'citizenship_status.string' => __('borrower_validation.citizenship_status_string'),
            'citizenship_status.max' => __('borrower_validation.citizenship_status_max'),
            'street_address.string' => __('borrower_validation.home_address_string'),
            'street_address.max' => __('borrower_validation.home_address_max'),
            'city.string' => __('borrower_validation.city_string'),
            'city.max' => __('borrower_validation.city_max'),
            'state.string' => __('borrower_validation.state_string'),
            'state.max' => __('borrower_validation.state_max'),
            'zip_code.string' => __('borrower_validation.zip_code_string'),
            'ownership.numeric' => __('borrower_validation.ownership_numeric'),
            'has_co_borrower.boolean' => __('borrower_validation.has_co_borrower_boolean'),
        ];
    }
}

