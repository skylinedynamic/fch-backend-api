<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Validation\Rule;

class UpdateBorrowerRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Personal Info
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            // 'email'                     => ['sometimes', 'required', 'email', 'max:255'],
            'phone_number' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore(User::where('entity_type', Borrower::class)->where('entity_id', (int)$this->route('id'))->value('id'))->whereNull('deleted_at')
            ],

            // 'signup_id'                 => ['sometimes', 'required', 'string', 'max:255', 'unique:borrowers,signup_id,' . $this->route('borrower')->id],
            'country' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required_without:other_city', 'string', 'max:255'],
            'other_city' => ['required_if:city,Others', 'nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'home_phone' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable'],
            'last_4_ssn' => ['nullable', 'string', 'max:4'],

            // Business Info
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_ein' => ['nullable', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:255', 'regex:/^[\d\s\(\)\-\+]+$/'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'business_city' => ['sometimes', 'required_without:business_other_city', 'string', 'max:255'],
            'business_country' => ['sometimes', 'required', 'string', 'max:255'],
            'business_country_code' => ['nullable', 'string', 'max:5'],
            'business_other_city' => ['required_if:business_city,Others', 'nullable', 'string', 'max:255'],
            'business_state' => ['sometimes', 'required', 'string', 'max:255'],
            'business_postal_code' => ['nullable', 'string', 'max:255'],
            'business_email' => ['nullable', 'email', 'max:255'],
            'business_website' => ['nullable', 'max:255'],
            'business_entity_type' => ['nullable', 'string', 'max:255'],
            'business_industry_type' => ['nullable', 'string', 'max:255'],
            'business_description' => ['nullable', 'string'],
            'business_logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,svg', 'max:5120'],
            'business_registration_number' => ['nullable', 'string', 'max:255'],
            'tax_identification_number' => ['nullable', 'string', 'max:255'],
            'duns_number' => ['nullable', 'string', 'max:255'],
            'state_file_number' => ['nullable', 'string', 'max:255'],
            'business_start_date' => ['nullable'],


            // Other
            'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'status' => ['nullable', 'string', 'max:255'],
            'current_tier' => ['nullable'],
            'assign_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'additional_data' => ['nullable', 'json'],
        ];
    }

    public function messages(): array
    {

        return [
            'first_name.required' => __('borrower_validation.first_name_required'),
            'last_name.required' => __('borrower_validation.last_name_required'),
            'email.required' => __('borrower_validation.email_required'),
            'email.email' => __('borrower_validation.email_email'),
            'email.unique' => __('borrower_validation.email_unique'),
            'phone_number.required' => __('borrower_validation.phone_number_required'),
            'phone_number.regex' => __('borrower_validation.phone_number_regex'),
            'signup_id.required' => __('borrower_validation.signup_id_required'),
            'signup_id.unique' => __('borrower_validation.signup_id_unique'),
            'country.required' => __('borrower_validation.country_required'),
            'state.required' => __('borrower_validation.state_required'),
            'city.required' => __('borrower_validation.city_required'),
            'city.required_without' => __('borrower_validation.city_required'),
            'business_country.required' => __('borrower_validation.business_country_required'),
            'business_state.required' => __('borrower_validation.business_state_required'),
            'business_city.required' => __('borrower_validation.business_city_required'),
            'business_city.required_without' => __('borrower_validation.business_city_required'),
            'other_city.required_if' => __('borrower_validation.other_city_required'),
            'business_other_city.required_if' => __('borrower_validation.business_other_city_required'),
            'business_email.email' => __('borrower_validation.business_email_email'),
            'business_website.url' => __('borrower_validation.business_website_url'),
            'business_logo.mimes' => __('borrower_validation.business_logo_mimes'),
            'business_logo.max' => __('borrower_validation.business_logo_max'),
            'monthly_revenue.numeric' => __('borrower_validation.monthly_revenue_numeric'),
            'annual_revenue.numeric' => __('borrower_validation.annual_revenue_numeric'),
            'partner_id.exists' => __('borrower_validation.partner_id_exists'),
            'business_phone.regex' => __('borrower_validation.business_phone_regex'),
            'assign_id.exists' => __('borrower_validation.assign_id_exists'),
        ];
    }
}
