<?php

namespace App\Http\Requests\Api\V1\Global\Website\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\KnownFundingType;
use Illuminate\Validation\Rule;
use App\Rules\UsPhoneNumber;

class StorePreQualificationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Step 1: Personal Information
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'phone_number' => [
                'required',
                'string',
                new UsPhoneNumber(),
            ],
            'business_website' => ['nullable', 'string', 'max:255'],
            'state_of_incorporation' => ['required', 'string', 'max:255'],

            // Step 2: Business Information
            'business_name' => ['required', 'string', 'max:255'],
            'industry_type' => ['required', 'string', 'max:255'],
            'business_structure' => ['required', 'string', 'max:255'],
            'years_in_business' => ['required', 'string', 'max:255'],
            'monthly_revenue' => ['required', 'numeric'],
            'annual_revenue' => ['required', 'numeric'],

            // Step 3: Funding Requirements
            'funding_requested_amount' => ['required', 'numeric'],
            'funding_urgency' => ['required', 'string', 'max:255'],
            // A lender product category (GET /common/funding-types), as the
            // website form offers and the borrower portal's pre-qualification takes.
            'purpose_of_funding' => ['required', 'string', 'max:255', new KnownFundingType('lead.purpose_of_funding_invalid')],
            'personal_credit_score' => ['required', 'string', 'max:255'],
            'estimated_business_credit_score' => ['required', 'string', 'max:255'],
            'existing_business_debt' => ['required', 'boolean'],
            'debt_balance' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->phone_number) {
            $phoneNumber = preg_replace('/\D/', '', $this->phone_number);

            if (strlen($phoneNumber) === 10) {
                $this->merge([
                    'phone_number' => sprintf(
                        '(%s) %s-%s',
                        substr($phoneNumber, 0, 3),
                        substr($phoneNumber, 3, 3),
                        substr($phoneNumber, 6)
                    ),
                ]);
            }
        }
    }

    public function messages(): array
    {
        return [
            // Personal Information
            'first_name.required' => __('lead.first_name_required'),
            'last_name.required' => __('lead.last_name_required'),
            'email.required' => __('lead.email_prequal_required'),
            'email.email' => __('lead.email_prequal_email'),
            'email.unique' => __('lead.email_taken'),
            'phone_number.required' => __('lead.phone_number_prequal_required'),
            'phone_number.regex' => __('lead.phone_number_format_invalid'),
            'state_of_incorporation.required' => __('lead.state_of_incorporation_required'),

            // Business Information
            'business_name.required' => __('lead.business_name_required'),
            'industry_type.required' => __('lead.industry_type_prequal_required'),
            'business_structure.required' => __('lead.business_structure_required'),
            'years_in_business.required' => __('lead.years_in_business_required'),
            'monthly_revenue.required' => __('lead.monthly_revenue_required'),
            'monthly_revenue.numeric' => __('lead.monthly_revenue_numeric'),
            'annual_revenue.required' => __('lead.annual_revenue_required'),
            'annual_revenue.numeric' => __('lead.annual_revenue_numeric'),

            // Funding Requirements
            'funding_requested_amount.required' => __('lead.funding_requested_amount_required'),
            'funding_requested_amount.numeric' => __('lead.funding_requested_amount_numeric'),
            'funding_urgency.required' => __('lead.funding_urgency_required'),
            'purpose_of_funding.required' => __('lead.purpose_of_funding_required'),
            'personal_credit_score.required' => __('lead.personal_credit_score_required'),
            'estimated_business_credit_score.required' => __('lead.estimated_business_credit_score_required'),
            'existing_business_debt.required' => __('lead.existing_business_debt_required'),
        ];
    }
}
