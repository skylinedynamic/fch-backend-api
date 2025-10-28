<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Models\User\User;
use Illuminate\Support\Facades\Auth;

class UpdatePersonalProfileRequest extends BaseApiRequest
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
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore(Auth::id())
            ],
            'home_address' => 'required|string|max:500',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip_code' => 'required|string|max:20',
            'country' => 'required|string|max:255',
            'home_phone' => 'nullable|string|max:20',
            'mobile_phone' => 'required|string|max:20', 
            'last_4_ssn' => 'required|string|size:4',
            'date_of_birth' => 'required|date_format:Y-m-d|before:today',
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
            'first_name.required' => __('borrower_validation.first_name_required'),
            'first_name.string' => __('borrower_validation.first_name_string'),
            'first_name.max' => __('borrower_validation.first_name_max'),
            'last_name.required' => __('borrower_validation.last_name_required'),
            'last_name.string' => __('borrower_validation.last_name_string'),
            'last_name.max' => __('borrower_validation.last_name_max'),
            'email.required' => __('borrower_validation.email_required'),
            'email.email' => __('borrower_validation.email_email'),
            'email.unique' => __('borrower_validation.email_unique'),
            'home_address.required' => __('borrower_validation.home_address_required'),
            'home_address.string' => __('borrower_validation.home_address_string'), 
            'home_address.max' => __('borrower_validation.home_address_max'),   
            'city.required' => __('borrower_validation.city_required'),
            'city.string' => __('borrower_validation.city_string'),
            'city.max' => __('borrower_validation.city_max'),
            'state.required' => __('borrower_validation.state_required'),
            'state.string' => __('borrower_validation.state_string'),
            'state.max' => __('borrower_validation.state_max'),
            'zip_code.required' => __('borrower_validation.zip_code_required'),
            'zip_code.string' => __('borrower_validation.zip_code_string'),
            'zip_code.max' => __('borrower_validation.zip_code_max'),
            'country.required' => __('borrower_validation.country_required'),
            'country.string' => __('borrower_validation.country_string'),
            'country.max' => __('borrower_validation.country_max'),
            'home_phone.string' => __('borrower_validation.home_phone_string'),
            'home_phone.max' => __('borrower_validation.home_phone_max'),
            'mobile_phone.required' => __('borrower_validation.mobile_phone_required'),
            'mobile_phone.string' => __('borrower_validation.mobile_phone_string'),
            'mobile_phone.max' => __('borrower_validation.mobile_phone_max'),
            'last_4_ssn.required' => __('borrower_validation.last_4_ssn_required'),
            'last_4_ssn.string' => __('borrower_validation.last_4_ssn_string'),
            'last_4_ssn.size' => __('borrower_validation.last_4_ssn_size'),
            'date_of_birth.required' => __('borrower_validation.date_of_birth_required'),
            'date_of_birth.date' => __('borrower_validation.date_of_birth_date'),
            'date_of_birth.before' => __('borrower_validation.date_of_birth_before'),
            'date_of_birth.date_format' => __('borrower_validation.date_of_birth_date_format'),
        ];
    }
}