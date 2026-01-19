<?php

namespace App\Http\Requests\Api\V1\Borrower\FundApplication;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreFinancialInfoRequest extends BaseApiRequest
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
            'id' => 'required|exists:funding_applications,id',
            'annual_revenue' => 'required|numeric|min:0',
            'monthly_revenue' => 'required|numeric|min:0',
            'business_bank_name' => 'required|string|max:255',
            'business_checking_start_date' => 'required|date',
            'self_reported_personal_credit_score' => 'required|string|max:255',
            'self_reported_business_credit_score' => 'required|string|max:255',
            'has_business_debt' => 'required|boolean',
            'debt_balance' => 'required_if:has_business_debt,true|nullable|numeric|min:0',
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
            'annual_revenue.numeric' => __('borrower_validation.annual_revenue_numeric'),
            'annual_revenue.min' => __('borrower_validation.annual_revenue_min'),
            'monthly_revenue.numeric' => __('borrower_validation.monthly_revenue_numeric'),
            'monthly_revenue.min' => __('borrower_validation.monthly_revenue_min'),
            'business_bank_name.string' => __('borrower_validation.business_bank_name_string'),
            'business_bank_name.max' => __('borrower_validation.business_bank_name_max'),
            'business_checking_start_date.date' => __('borrower_validation.business_checking_start_date_date'),
            'self_reported_personal_credit_score.string' => __('borrower_validation.self_reported_personal_credit_score_string'),
            'self_reported_personal_credit_score.max' => __('borrower_validation.self_reported_personal_credit_score_max'),
            'self_reported_business_credit_score.string' => __('borrower_validation.self_reported_business_credit_score_string'),
            'self_reported_business_credit_score.max' => __('borrower_validation.self_reported_business_credit_score_max'),
            'has_business_debt.boolean' => __('borrower_validation.has_business_debt_boolean'),
            'debt_balance.required_if' => __('borrower_validation.debt_balance_required_if'),
            'debt_balance.numeric' => __('borrower_validation.debt_balance_numeric'),
            'debt_balance.min' => __('borrower_validation.debt_balance_min'),
        ];
    }
}
