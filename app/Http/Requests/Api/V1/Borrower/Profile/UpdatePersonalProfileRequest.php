<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use App\Models\User\User;
use Illuminate\Support\Facades\Auth;
use App\Rules\UsPostalCode;

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
                Rule::unique('users', 'email')->ignore(Auth::id())->whereNull('deleted_at')
            ],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'country' => 'required|string|max:255',
            'country_code' => 'required|string|max:5',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'zip_code' => ['required', 'string', new UsPostalCode()],
            'home_address' => 'required|string|max:500',
            'home_phone' => ['nullable', 'string', 'max:20', 'regex:/^[\d\s\(\)\-\+]+$/'],
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
            'country.required' => __('borrower_validation.country_required'),
            'country.string' => __('borrower_validation.country_string'),
            'country.max' => __('borrower_validation.country_max'),
            'country_code.required' => __('borrower_validation.country_code_required'),
            'country_code.string' => __('borrower_validation.country_code_string'),
            'country_code.max' => __('borrower_validation.country_code_max'),
            'home_phone.string' => __('borrower_validation.home_phone_string'),
            'home_phone.max' => __('borrower_validation.home_phone_max'),
            'home_phone.regex' => __('borrower_validation.home_phone_regex'),
            'phone_number.required' => __('borrower_validation.phone_number_required'),
            'phone_number.string' => __('borrower_validation.phone_number_string'),
            'phone_number.max' => __('borrower_validation.phone_number_max'),
            'phone_number.regex' => __('borrower_validation.phone_number_regex'),
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