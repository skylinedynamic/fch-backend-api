<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateBorrowerBusinessFinancialsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $numericFields = [
            'monthly_revenue',
            'annual_revenue',
            'avg_bank_balance',
            'average_monthly_deposits',
            'current_business_debt',
            'profit_margin'
        ];

        foreach ($numericFields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => str_replace(',', '', $this->input($field))
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'currency'                  => ['nullable', 'string', 'max:255'],
            'years_in_business'         => ['nullable', 'string', 'max:255'],
            'monthly_revenue'           => ['nullable', 'numeric', 'min:0'],
            'annual_revenue'            => ['nullable', 'numeric', 'min:0'],
            'avg_bank_balance'          => ['nullable', 'numeric', 'min:0'],
            'average_monthly_deposits'  => ['nullable', 'numeric', 'min:0'],
            'monthly_deposit_count'     => ['nullable', 'integer', 'min:0'],
            'profit_margin'             => ['nullable', 'numeric'],
            'current_business_debt'     => ['nullable', 'numeric', 'min:0'],
            'fico_score'                => ['nullable', 'integer', 'min:0'],
            'paydex_score'              => ['nullable', 'integer', 'min:0'],
            'number_of_employees'       => ['nullable', 'integer', 'min:0'],
            'nsf_days'                  => ['nullable', 'integer', 'min:0'],
            'funding_urgency'           => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_revenue.numeric'   => __('borrower_validation.monthly_revenue_numeric'),
            'annual_revenue.numeric'    => __('borrower_validation.annual_revenue_numeric'),
        ];
    }
}
