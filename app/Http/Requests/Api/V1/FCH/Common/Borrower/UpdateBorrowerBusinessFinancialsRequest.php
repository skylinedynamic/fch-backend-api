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
            'profit_margin',
            'annual_personal_income'
        ];

        foreach ($numericFields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => str_replace(',', '', $this->input($field))
                ]);
            }
        }

        if ($this->has('bank_accounts') && is_array($this->input('bank_accounts'))) {
            $bankAccounts = array_map(function ($account) {
                if (is_array($account)) {
                    if (isset($account['account_open_date']) && ($account['account_open_date'] === '' || $account['account_open_date'] === 'null')) {
                        $account['account_open_date'] = null;
                    }
                    if (isset($account['id']) && (empty($account['id']) || $account['id'] === '')) {
                        unset($account['id']);
                    }
                }
                return $account;
            }, $this->input('bank_accounts'));

            $this->merge(['bank_accounts' => $bankAccounts]);
        }
    }

    public function rules(): array
    {
        return [
            'currency'                  => ['nullable', 'string', 'max:255'],
            'years_in_business'         => ['nullable', 'string', 'max:255'],
            'monthly_revenue'           => ['nullable', 'numeric', 'min:0'],
            'annual_revenue'            => ['nullable', 'numeric', 'min:0'],
            'annual_personal_income'    => ['nullable', 'numeric', 'min:0'],
            'avg_bank_balance'          => ['nullable', 'numeric', 'min:0'],
            'average_monthly_deposits'  => ['nullable', 'numeric', 'min:0'],
            'monthly_deposit_count'     => ['nullable', 'integer', 'min:0'],
            'profit_margin'             => ['nullable', 'numeric'],
            'current_business_debt'     => ['nullable', 'numeric', 'min:0'],
            'fico_score'                => ['nullable', 'integer', 'min:0'],
            'paydex_score'              => ['nullable', 'integer', 'min:0'],
            'number_of_employees'       => ['nullable', 'integer', 'min:0'],
            'nsf_count'                 => ['nullable', 'integer', 'min:0', 'max:99999'],
            'negative_days'             => ['nullable', 'integer', 'min:0', 'max:99999'],
            'funding_urgency'           => ['nullable', 'string', 'max:255'],
            'bank_accounts'             => ['nullable', 'array'],
            'bank_accounts.*.id'        => ['nullable', 'integer', 'exists:borrower_bank_accounts,id'],
            'bank_accounts.*.bank_name' => ['required_with:bank_accounts.*', 'string', 'max:255'],
            'bank_accounts.*.account_open_date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'monthly_revenue.numeric'   => __('borrower_validation.monthly_revenue_numeric'),
            'annual_revenue.numeric'    => __('borrower_validation.annual_revenue_numeric'),
            'nsf_count.integer'         => __('borrower_validation.nsf_count_integer'),
            'nsf_count.min'             => __('borrower_validation.nsf_count_min'),
            'nsf_count.max'             => __('borrower_validation.nsf_count_max'),
            'negative_days.integer'     => __('borrower_validation.negative_days_integer'),
            'negative_days.min'         => __('borrower_validation.negative_days_min'),
            'negative_days.max'         => __('borrower_validation.negative_days_max'),
            'bank_accounts.*.bank_name.required_with' => __('borrower_validation.bank_name_required'),
        ];
    }
}
