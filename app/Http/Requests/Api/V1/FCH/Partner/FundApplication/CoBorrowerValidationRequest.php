<?php

namespace App\Http\Requests\Api\V1\FCH\Partner\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\UsPostalCode;

class CoBorrowerValidationRequest extends BaseApiRequest
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
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'role' => 'required|string|max:255',
            // A percentage of the business.
            'ownership' => 'required|numeric|min:0|max:100',
            'email' => 'required|email|max:255',
            'mobile_phone' => 'required|string|max:20|regex:/^[\d\s\(\)\-\+]+$/',
            'home_phone' => 'nullable|string|max:20|regex:/^[\d\s\(\)\-\+]+$/',
            'date_of_birth' => 'required|date',
            'ssn' => 'required|digits:9',
            'street_address' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'zip_code' => ['nullable', 'string', new UsPostalCode()],
            'driver_license_number' => 'nullable|string|max:255',
            'driver_license_state' => 'nullable|string|max:255',
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
            'first_name.required' => __('borrower_validation.co_borrower_first_name_required'),
            'last_name.required' => __('borrower_validation.co_borrower_last_name_required'),
            'role.required' => __('borrower_validation.co_borrower_title_role_required'),
            'ownership.required' => __('borrower_validation.co_borrower_ownership_required'),
            'email.required' => __('borrower_validation.co_borrower_email_required'),
            'email.email' => __('borrower_validation.co_borrower_email_email'),
            'mobile_phone.required' => __('borrower_validation.co_borrower_mobile_phone_required'),
            'date_of_birth.required' => __('borrower_validation.co_borrower_dob_required'),
            'ssn.required' => __('borrower_validation.co_borrower_ssn_required'),
            'street_address.required' => __('borrower_validation.co_borrower_street_address_required'),
            'country.required' => __('borrower_validation.co_borrower_country_required'),
            'state.required' => __('borrower_validation.co_borrower_state_required'),
            'city.required' => __('borrower_validation.co_borrower_city_required'),
        ];
    }
}

