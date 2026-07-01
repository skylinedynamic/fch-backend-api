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
            'currency' => 'required|string|max:10',
            'years_in_business' => 'required|string',
            'monthly_revenue' => 'required|numeric|min:0|max:999999999.99',
            'annual_revenue' => 'required|numeric|min:0|max:999999999.99',
            'current_business_debt' => 'nullable|numeric|min:0|max:999999999.99',
            'average_monthly_deposits' => 'required|numeric|min:0|max:999999999.99',
            'monthly_deposit_count' => 'nullable|integer|min:0',
            'avg_bank_balance' => 'nullable|numeric|min:0|max:999999999.99',
            'nsf_count' => 'nullable|integer|min:0|max:99999',
            'negative_days' => 'nullable|integer|min:0|max:99999',
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

            'nsf_count.required' => __('borrower_validation.nsf_count_required'),
            'nsf_count.integer' => __('borrower_validation.nsf_count_integer'),
            'nsf_count.min' => __('borrower_validation.nsf_count_min'),
            'nsf_count.max' => __('borrower_validation.nsf_count_max'),

            'negative_days.required' => __('borrower_validation.negative_days_required'),
            'negative_days.integer' => __('borrower_validation.negative_days_integer'),
            'negative_days.min' => __('borrower_validation.negative_days_min'),
            'negative_days.max' => __('borrower_validation.negative_days_max'),
        ];
    }
}