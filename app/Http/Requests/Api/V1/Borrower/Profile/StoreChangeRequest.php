<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Rules\NotFutureDate;
use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Rules\AdultDateOfBirth;
use App\Rules\BusinessEin;
use App\Rules\UsPostalCode;
use App\Rules\UsPhoneNumber;
use App\Rules\DunsNumber;
use App\Rules\FicoScore;
use App\Rules\Ssn;

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
    /**
     * An EIN is nine digits; the hyphen is only how it is written. The modal
     * that posts this request does not strip it, while the main save does, so
     * without reducing it here the same field would be judged by two different
     * standards depending on which screen the borrower used.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('field_name') === 'business_ein' && $this->has('new_value')) {
            $this->merge(['new_value' => BusinessEin::normalize($this->input('new_value'))]);
        }

        if ($this->input('field_name') === 'ssn' && $this->has('new_value')) {
            $this->merge(['new_value' => Ssn::canonical($this->input('new_value'))]);
        }
    }

    public function rules()
    {
        $fieldName = $this->input('field_name');

        $allRules = [
            // Business Info
            'business_name' => 'required|string|max:255',
            'business_ein' => ['required', new BusinessEin()],
            'business_phone' => ['nullable', 'string', new UsPhoneNumber()],
            // BY DESIGN (BUG-071): no uniqueness rule. Mail goes to the client's
            // sign-in email, never to this one (it is only shown and printed on
            // documents), and two clients of one company may share an office
            // address. Only the sign-in email (`email` below) must be unique.
            'business_email' => 'required|email|max:255',
            'duns_number' => ['nullable', 'string', new DunsNumber()],
            'business_address' => 'required|string|max:255',
            'business_country' => 'required|string|max:255',
            'business_country_code' => 'required|string|max:5',
            'business_city' => 'required|string|max:255',
            'business_state' => 'required|string|max:255',
            'business_zip_code' => ['required', 'string', new UsPostalCode()],
            'business_website' => 'nullable|max:255',
            'business_entity_type' => 'required|string|max:255',
            'business_industry_type' => 'required|string|max:255',
            'business_start_date' => ['required', 'date', new NotFutureDate()], // BUG-007
            'personal_credit_score' => ['required', new FicoScore()],
            // Financial Info
            'currency' => 'required|string|max:10',
            'years_in_business' => 'required|string',
            'monthly_revenue' => 'required|numeric|min:0|max:999999999.99',
            'annual_revenue' => 'required|numeric|min:0|max:999999999.99',
            'annual_personal_income' => 'required|numeric|min:0|max:999999999.99',
            'nsf_count' => 'nullable|integer|min:0|max:99999',
            'negative_days' => 'nullable|integer|min:0|max:99999',

            // Bank Accounts
            'bank_name' => 'required|string|max:255',
            'account_open_date' => 'required|date|before_or_equal:today',

            // Personal Info
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore(Auth::id())->whereNull('deleted_at')
            ],
            'phone_number' => ['required', 'string', new UsPhoneNumber()],
            'country' => 'required|string|max:255',
            'country_code' => 'required|string|max:5',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'zip_code' => ['required', 'string', new UsPostalCode()],
            'home_address' => 'required|string|max:500',
            'home_phone' => ['nullable', 'string', new UsPhoneNumber()],
            // The full SSN, as on the profile itself; the last four alone are not taken.
            'ssn' => ['required', new Ssn()],
            'date_of_birth' => ['required', 'date_format:Y-m-d', new AdultDateOfBirth()],
        ];

        $newValueRule = $allRules[$fieldName] ?? 'required';

        return [
            'field_name' => 'required|string',
            'new_value' => $newValueRule,
            'entity_type' => 'nullable|string',
            'entity_id' => 'nullable|integer',
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
