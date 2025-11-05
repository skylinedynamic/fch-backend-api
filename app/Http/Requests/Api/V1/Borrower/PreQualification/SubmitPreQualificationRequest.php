<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;

class SubmitPreQualificationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable','integer','exists:pre_qualification_requests,id'],
            
            'full_name' => ['required','string','max:255'],
            'business_website' => ['nullable','url','max:255'],
            'email' => ['required','email','max:255'],
            'phone' => ['required','string','max:25'],
            'state_of_incorporation' => ['required','string','max:255'],

            'business_name' => ['required','string','max:255'],
            'industry' => ['required','string','max:255'],
            'business_structure' => ['required','string','max:255'],
            'monthly_revenue' => ['required','numeric','min:0'],
            'annual_revenue' => ['nullable','numeric','min:0'],
            'time_in_business' => ['required','string','max:255'],

            'funding_requested_amount' => ['required','numeric','min:0'],
            'funding_urgency' => ['required','string','max:255'],
            'purpose_of_funding' => ['required','string','max:255'],
            'personal_credit_score' => ['required','string','max:255'],
            'estimated_business_credit_score' => ['required','string','max:255'],
            'existing_business_debt' => ['required','boolean'],
            'debt_balance' => ['nullable','numeric','min:0'],
            'notes' => ['nullable','string'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $existingDebt = $this->boolean('existing_business_debt');
            if ($existingDebt && !$this->filled('debt_balance')) {
                $validator->errors()->add('debt_balance', __('borrower_validation.debt_balance_required'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'id.integer' => __('borrower_validation.id_integer'),
            'id.exists' => __('borrower_validation.id_exists'),

            'full_name.required' => __('borrower_validation.full_name_required'),
            'full_name.string' => __('borrower_validation.full_name_string'),
            'full_name.max' => __('borrower_validation.full_name_max'),

            'business_website.url' => __('borrower_validation.business_website_url'),
            'business_website.max' => __('borrower_validation.business_website_max'),

            'email.required' => __('borrower_validation.email_required'),
            'email.email' => __('borrower_validation.email_email'),
            'email.max' => __('borrower_validation.email_max'),

            'phone.required' => __('borrower_validation.phone_required'),
            'phone.string' => __('borrower_validation.phone_string'),
            'phone.max' => __('borrower_validation.phone_max'),

            'state_of_incorporation.required' => __('borrower_validation.state_of_incorporation_required'),
            'state_of_incorporation.string' => __('borrower_validation.state_of_incorporation_string'),
            'state_of_incorporation.max' => __('borrower_validation.state_of_incorporation_max'),

            'business_name.required' => __('borrower_validation.business_name_required'),
            'business_name.string' => __('borrower_validation.business_name_string'),
            'business_name.max' => __('borrower_validation.business_name_max'),

            'industry.required' => __('borrower_validation.industry_required'),
            'industry.string' => __('borrower_validation.industry_string'),
            'industry.max' => __('borrower_validation.industry_max'),

            'business_structure.required' => __('borrower_validation.business_structure_required'),
            'business_structure.string' => __('borrower_validation.business_structure_string'),
            'business_structure.max' => __('borrower_validation.business_structure_max'),

            'monthly_revenue.required' => __('borrower_validation.monthly_revenue_required'),
            'monthly_revenue.numeric' => __('borrower_validation.monthly_revenue_numeric'),
            'monthly_revenue.min' => __('borrower_validation.monthly_revenue_min'),

            'annual_revenue.numeric' => __('borrower_validation.annual_revenue_numeric'),
            'annual_revenue.min' => __('borrower_validation.annual_revenue_min'),

            'time_in_business.required' => __('borrower_validation.time_in_business_required'),
            'time_in_business.string' => __('borrower_validation.time_in_business_string'),
            'time_in_business.max' => __('borrower_validation.time_in_business_max'),

            'funding_requested_amount.required' => __('borrower_validation.funding_requested_amount_required'),
            'funding_requested_amount.numeric' => __('borrower_validation.funding_requested_amount_numeric'),
            'funding_requested_amount.min' => __('borrower_validation.funding_requested_amount_min'),

            'funding_urgency.required' => __('borrower_validation.funding_urgency_required'),
            'funding_urgency.string' => __('borrower_validation.funding_urgency_string'),
            'funding_urgency.max' => __('borrower_validation.funding_urgency_max'),

            'purpose_of_funding.required' => __('borrower_validation.purpose_of_funding_required'),
            'purpose_of_funding.string' => __('borrower_validation.purpose_of_funding_string'),
            'purpose_of_funding.max' => __('borrower_validation.purpose_of_funding_max'),

            'personal_credit_score.required' => __('borrower_validation.personal_credit_score_required'),
            'personal_credit_score.string' => __('borrower_validation.personal_credit_score_string'),
            'personal_credit_score.max' => __('borrower_validation.personal_credit_score_max'),

            'estimated_business_credit_score.required' => __('borrower_validation.estimated_business_credit_score_required'),
            'estimated_business_credit_score.string' => __('borrower_validation.estimated_business_credit_score_string'),
            'estimated_business_credit_score.max' => __('borrower_validation.estimated_business_credit_score_max'),

            'existing_business_debt.required' => __('borrower_validation.existing_business_debt_required'),
            'existing_business_debt.boolean' => __('borrower_validation.existing_business_debt_boolean'),

            'debt_balance.numeric' => __('borrower_validation.debt_balance_numeric'),
            'debt_balance.min' => __('borrower_validation.debt_balance_min'),
        ];
    }
}