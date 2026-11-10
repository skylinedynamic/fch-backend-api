<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Borrower;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Rules\FicoScore;
use App\Rules\BusinessCreditScore;

class UpdateBorrowerBusinessFinancialsRequest extends BaseApiRequest
{
    /** Money fields: 0 up to amountMax() (BUG-006). */
    private const AMOUNT_FIELDS = [
        'monthly_revenue',
        'annual_revenue',
        'annual_personal_income',
        'avg_bank_balance',
        'average_monthly_deposits',
        'current_business_debt',
    ];

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
            'monthly_revenue'           => ['nullable', 'numeric', 'min:0', 'max:' . self::amountMax()],
            'annual_revenue'            => ['nullable', 'numeric', 'min:0', 'max:' . self::amountMax()],
            'annual_personal_income'    => ['nullable', 'numeric', 'min:0', 'max:' . self::amountMax()],
            'avg_bank_balance'          => ['nullable', 'numeric', 'min:0', 'max:' . self::amountMax()],
            'average_monthly_deposits'  => ['nullable', 'numeric', 'min:0', 'max:' . self::amountMax()],
            'monthly_deposit_count'     => ['nullable', 'integer', 'min:0'],
            'profit_margin'             => ['nullable', 'numeric', 'between:' . self::marginMin() . ',' . self::marginMax()],
            'current_business_debt'     => ['nullable', 'numeric', 'min:0', 'max:' . self::amountMax()],
            'fico_score'                => ['nullable', new FicoScore()],
            'paydex_score'              => ['nullable', new BusinessCreditScore()],
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
        $amountMessages = [];
        foreach (self::AMOUNT_FIELDS as $field) {
            $amountMessages["{$field}.min"] = __('borrower_validation.amount_min');
            $amountMessages["{$field}.max"] = __('borrower_validation.amount_max', ['max' => number_format(self::amountMax(), 2)]);
        }

        return $amountMessages + [
            'monthly_revenue.numeric'   => __('borrower_validation.monthly_revenue_numeric'),
            'profit_margin.between'     => __('borrower_validation.profit_margin_between', ['min' => self::marginMin(), 'max' => self::marginMax()]),
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

    /** Ceiling on every amount here - borrower.financials.amount_max (BUG-006). */
    private static function amountMax(): float
    {
        return (float) config('borrower.financials.amount_max', 999999999.99);
    }

    /** Profit margin in percent; negative when the business runs at a loss. */
    private static function marginMin(): int
    {
        return (int) config('borrower.financials.profit_margin.min', -100);
    }

    private static function marginMax(): int
    {
        return (int) config('borrower.financials.profit_margin.max', 100);
    }
}
