<?php

namespace App\Http\Requests\Api\V1\Borrower\Profile;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Support\Profile\LockedFields;
use Illuminate\Support\Facades\Auth;

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
            'monthly_revenue' => 'required|numeric|min:0|max:999999999.99',
            'annual_revenue' => 'required|numeric|min:0|max:999999999.99',
            'current_business_debt' => 'nullable|numeric|min:0|max:999999999.99',
            'average_monthly_deposits' => 'nullable|numeric|min:0|max:999999999.99',
            'monthly_deposit_count' => 'nullable|integer|min:0',
            'avg_bank_balance' => 'nullable|numeric|min:0|max:999999999.99',
            'annual_personal_income' => 'nullable|numeric|min:0|max:999999999.99',
            'nsf_count' => 'nullable|integer|min:0|max:99999',
            'negative_days' => 'nullable|integer|min:0|max:99999',
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.id' => 'nullable|integer|exists:borrower_bank_accounts,id',
            'bank_accounts.*.bank_name' => 'required_with:bank_accounts.*|string|max:255',
            'bank_accounts.*.account_open_date' => 'nullable|date|before_or_equal:today',
        ];

    }

    /**
     * Saved revenues, income and bank accounts may only come back unchanged:
     * changing them is a Change Request for the host to approve (BUG-054).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Read fresh: the lock is judged against what is stored now.
            $borrower = Auth::user()?->entity()->first();
            if (!$borrower instanceof Borrower) {
                return;
            }

            $message = __('borrower_validation.locked_field_change_request');

            foreach (LockedFields::changed($borrower->getAttributes(), $this->all(), Borrower::LOCKED_FINANCIAL_FIELDS) as $field) {
                $validator->errors()->add($field, $message);
            }

            $saved = BorrowerBankAccount::where('borrower_id', $borrower->id)->get()->keyBy('id');
            foreach ((array) $this->input('bank_accounts', []) as $index => $row) {
                $account = is_array($row) && !empty($row['id']) ? $saved->get($row['id']) : null;
                if (!$account) {
                    continue;
                }

                foreach (LockedFields::changed($account->getAttributes(), $row, Borrower::LOCKED_BANK_ACCOUNT_FIELDS) as $field) {
                    $validator->errors()->add("bank_accounts.{$index}.{$field}", $message);
                }
            }
        });
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