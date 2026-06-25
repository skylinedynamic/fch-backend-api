<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateFinancialInfoRequest extends BaseApiRequest
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
            'currency'                              => 'required|string|max:10',
            'years_in_business'                     => 'required|string',
            'monthly_revenue'                       => 'required|numeric|min:0|max:999999999.99',
            'annual_revenue'                        => 'required|numeric|min:0|max:999999999.99',
            'annual_personal_income'                => 'required|numeric|min:0|max:999999999.99',
            'bank_accounts'                         => 'nullable|array',
            'bank_accounts.*.id'                    => 'nullable|integer|exists:borrower_bank_accounts,id',
            'bank_accounts.*.bank_name'             => 'required_with:bank_accounts.*|string|max:255',
            'bank_accounts.*.account_open_date'     => 'nullable|date|before_or_equal:today',
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
            'currency.required' => __('borrower_validation.currency_required'),
            'currency.string' => __('borrower_validation.currency_string'),
            'currency.max' => __('borrower_validation.currency_max'),
            
            'years_in_business.required' => __('borrower_validation.years_in_business_required'),
            'years_in_business.string' => __('borrower_validation.years_in_business_string'),

            
            'monthly_revenue.required' => __('borrower_validation.monthly_revenue_required'),
            'monthly_revenue.numeric' => __('borrower_validation.monthly_revenue_numeric'),
            'monthly_revenue.min' => __('borrower_validation.monthly_revenue_min'),
            'monthly_revenue.max' => __('borrower_validation.monthly_revenue_max'),
            
            'annual_revenue.numeric' => __('borrower_validation.annual_revenue_numeric'),
            'annual_revenue.min' => __('borrower_validation.annual_revenue_min'),
            'annual_revenue.max' => __('borrower_validation.annual_revenue_max'),
            'annual_revenue.required' => __('borrower_validation.annual_revenue_required'),

            'annual_personal_income.numeric' => __('borrower_validation.annual_personal_income_numeric'),
            'annual_personal_income.min' => __('borrower_validation.annual_personal_income_min'),
            'annual_personal_income.max' => __('borrower_validation.annual_personal_income_max'),
            'annual_personal_income.required' => __('borrower_validation.annual_personal_income_required'),
        ];
    }
}