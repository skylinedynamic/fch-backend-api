<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateFundingSequencingVisibilityRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lender_visibilities'                        => 'nullable|array',
            'lender_visibilities.*.lender_id'            => 'required|exists:lenders,id',
            'lender_visibilities.*.lender_product_category_id' => 'required|integer|exists:lender_product_categories,id',
            'lender_visibilities.*.min_fico'             => ['required', 'integer'],
            'lender_visibilities.*.min_tib_months'       => ['required', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.min_monthly_revenue'  => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_monthly_deposits' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_average_daily_balance' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_avg_deposits'     => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_deposit_count'    => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.max_nsf_count'        => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.max_negative_days'    => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.min_dscr'             => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'lender_visibilities.*.allowed_states'       => 'nullable|array',
            'lender_visibilities.*.allowed_states.*'     => 'string',
            'lender_visibilities.*.restricted_states'    => 'nullable|array',
            'lender_visibilities.*.restricted_states.*'  => 'string',
            'lender_visibilities.*.allowed_industries'   => 'nullable|array',
            'lender_visibilities.*.allowed_industries.*' => 'string',
            'lender_visibilities.*.restricted_industries' => 'nullable|array',
            'lender_visibilities.*.restricted_industries.*' => 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'lender_visibilities.*.lender_id.required'   => __('funding_sequencing.lender_id_required'),
            'lender_visibilities.*.lender_id.exists'     => __('funding_sequencing.lender_id_exists'),
            
            'lender_visibilities.*.min_monthly_revenue.regex'  => __('funding_sequencing.min_monthly_revenue_regex'),
            'lender_visibilities.*.min_monthly_deposits.regex' => __('funding_sequencing.min_monthly_deposits_regex'),
            'lender_visibilities.*.min_average_daily_balance.regex' => __('funding_sequencing.min_average_daily_balance_regex'),
            'lender_visibilities.*.min_dscr.regex'             => __('funding_sequencing.min_dscr_regex'),
        ];
    }
}
