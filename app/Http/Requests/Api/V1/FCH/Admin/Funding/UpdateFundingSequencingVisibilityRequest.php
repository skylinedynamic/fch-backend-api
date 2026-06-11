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
            'lenders'                        => 'nullable|array',
            'lenders.*.lender_id'            => 'required|exists:lenders,id',
            'lenders.*.min_fico'             => ['required', 'integer'],
            'lenders.*.min_tib_months'       => ['required', 'integer', 'min:0', 'digits_between:1,5'],
            'lenders.*.min_monthly_revenue'  => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lenders.*.min_monthly_deposits' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lenders.*.min_average_daily_balance' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lenders.*.max_nsf_count'        => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lenders.*.max_negative_days'    => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lenders.*.min_dscr'             => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'lenders.*.allowed_states'       => 'nullable|array',
            'lenders.*.allowed_states.*'     => 'string',
            'lenders.*.restricted_states'    => 'nullable|array',
            'lenders.*.restricted_states.*'  => 'string',
            'lenders.*.allowed_industries'   => 'nullable|array',
            'lenders.*.allowed_industries.*' => 'string',
            'lenders.*.restricted_industries' => 'nullable|array',
            'lenders.*.restricted_industries.*' => 'string',
            'lenders.*.require_completed_application' => 'nullable|boolean',
            'lenders.*.require_documents_uploaded'    => 'nullable|boolean',
            'lenders.*.allowed_documents'             => 'required_if:lenders.*.require_documents_uploaded,true,1|nullable|array',
            'lenders.*.allowed_documents.*'           => 'integer',
            'lenders.*.manual_review_allowed'         => 'nullable|boolean',
            'lenders.*.exception_allowed'             => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'lenders.*.lender_id.required'   => __('funding_sequencing.lender_id_required'),
            'lenders.*.lender_id.exists'     => __('funding_sequencing.lender_id_exists'),
            
            'lenders.*.min_monthly_revenue.required'      => __('funding_sequencing.min_monthly_revenue_required'),
            'lenders.*.min_monthly_revenue.regex'      => __('funding_sequencing.min_monthly_revenue_regex'),
            'lenders.*.min_monthly_deposits.required'     => __('funding_sequencing.min_monthly_deposits_required'),
            'lenders.*.min_monthly_deposits.regex'     => __('funding_sequencing.min_monthly_deposits_regex'),
            'lenders.*.min_average_daily_balance.required'=> __('funding_sequencing.min_average_daily_balance_required'),
            'lenders.*.min_average_daily_balance.regex'=> __('funding_sequencing.min_average_daily_balance_regex'),
            'lenders.*.min_dscr.regex'                 => __('funding_sequencing.min_dscr_regex'),
            'lenders.*.min_tib_months.required'        => __('funding_sequencing.min_tib_months_required'),
            'lenders.*.min_fico.required'              => __('funding_sequencing.min_fico_required'),
            'lenders.*.min_fico.integer'               => __('funding_sequencing.min_fico_integer'),
            'lenders.*.allowed_documents.required_if'  => __('funding_sequencing.allowed_documents_required_if'),
        ];
    }
}
