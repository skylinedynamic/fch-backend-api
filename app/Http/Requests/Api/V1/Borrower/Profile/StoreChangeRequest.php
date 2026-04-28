<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreChangeRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $fieldName = $this->input('field_name');

        $allRules = [
            // Business Info
            'business_name' => 'required|string|max:255',
            'business_ein' => 'required|string|size:10',
            'business_phone' => 'nullable|string|max:20',
            'business_email' => 'required|email|max:255',
            'duns_number' => 'nullable|string|size:9',
            'business_address' => 'required|string|max:255',
            'business_country' => 'required|string|max:255',
            'business_country_code' => 'required|string|max:5',
            'business_city' => 'required|string|max:255',
            'business_state' => 'required|string|max:255',
            'business_zip_code' => 'required|string|size:5',
            'business_website' => 'nullable|max:255',
            'business_entity_type' => 'required|string|max:255',
            'business_industry_type' => 'required|string|max:255',
            'business_start_date' => 'required|date',
            'personal_credit_score' => 'required|integer',

            // Financial Info
            'currency' => 'required|string|max:10',
            'years_in_business' => 'required|string',
            'monthly_revenue' => 'required|numeric|min:0|max:999999999.99',
            'annual_revenue' => 'required|numeric|min:0|max:999999999.99',

            // Personal Info
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
            'zip_code' => 'required|string|max:20',
            'home_address' => 'required|string|max:500',
            'home_phone' => ['nullable', 'string', 'max:20', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'last_4_ssn' => 'required|string|size:4',
            'date_of_birth' => 'required|date_format:Y-m-d|before:today',
        ];

        $newValueRule = $allRules[$fieldName] ?? 'required';

        return [
            'field_name' => 'required|string',
            'new_value' => $newValueRule,
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $fieldName = $this->input('field_name');
        return [
            'new_value.required' => __('borrower_validation.' . $fieldName . '_required'),
            'new_value.string' => __('borrower_validation.' . $fieldName . '_string'),
            'new_value.size' => __('borrower_validation.' . $fieldName . '_size'),
            'new_value.max' => __('borrower_validation.' . $fieldName . '_max'),
            'new_value.min' => __('borrower_validation.' . $fieldName . '_min'),
            'new_value.email' => __('borrower_validation.' . $fieldName . '_email'),
            'new_value.numeric' => __('borrower_validation.' . $fieldName . '_numeric'),
            'new_value.integer' => __('borrower_validation.' . $fieldName . '_integer'),
            'new_value.date' => __('borrower_validation.' . $fieldName . '_date'),
            'new_value.date_format' => __('borrower_validation.' . $fieldName . '_date_format'),
            'new_value.before' => __('borrower_validation.' . $fieldName . '_before'),
            'new_value.regex' => __('borrower_validation.' . $fieldName . '_regex'),
            'new_value.unique' => __('borrower_validation.' . $fieldName . '_unique'),
            'new_value.url' => __('borrower_validation.' . $fieldName . '_url'),
        ];
    }
}
