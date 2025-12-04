<?php

namespace App\Http\Requests\Api\V1\Borrower\PreQualification;

use App\Http\Requests\Api\V1\BaseApiRequest;

class FundingInfoRequest extends BaseApiRequest
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
            'funding_requested_amount' => ['required','numeric','gt:0'],
            'funding_urgency' => ['required','string','max:255'],
            'purpose_of_funding' => ['required','string','max:255'],
            'personal_credit_score' => ['required','string','max:255'],
            'estimated_business_credit_score' => ['required','string','max:255'],
            'existing_business_debt' => ['required','boolean'],
            'debt_balance' => ['nullable','numeric','gt:0','required_if:existing_business_debt,true'],
            'notes' => ['nullable','string'],
        ];
    }

    public function messages(): array
    {
        return [
            
            'funding_requested_amount.required' => __('borrower_validation.funding_requested_amount_required'),
            'funding_requested_amount.numeric' => __('borrower_validation.funding_requested_amount_numeric'),
            'funding_requested_amount.gt' => __('borrower_validation.funding_requested_amount_gt'),

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
            'debt_balance.gt' => __('borrower_validation.debt_balance_gt'),
        ];
    }
}
