<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateFundingSequencingRequest extends BaseApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('min_amount') || $this->has('max_amount')) {
            $this->merge([
                'amount_range' => $this->min_amount . '-' . $this->max_amount
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'subtitle'         => 'required|string|max:255',
            'funding_group_id' => 'required|exists:funding_groups,id',
            'funding_type_id'  => 'required|exists:funding_types,id',
            'lender_id'        => 'required|exists:lenders,id',
            'description'      => 'nullable|string',
            'min_amount'       => 'required|numeric|gt:0',
            'max_amount'       => 'required|numeric|gt:0',
            'uses'             => 'nullable|array',
            'uses.*'           => 'string|max:255',
            'qualify_reasons'  => 'nullable|array',
            'qualify_reasons.*'=> 'string|max:255',
            'readiness_factors'=> 'required|array|min:1',
            'readiness_factors.*'=> 'string|max:255',
            'missing_documents'=> 'nullable|array',
            'missing_documents.*'=> 'string|max:255',
            'icon'             => 'required|string|max:255',
            'icon_color'       => 'nullable|string|max:255',
            'status'           => 'nullable|string|in:Active,Inactive',
            'sort_order'       => 'nullable|integer',
            'action_url'       => 'nullable|string|max:255',
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
            'lender_id.required'         => __('funding_sequencing.lender_id_required'),
            'lender_id.exists'           => __('funding_sequencing.lender_id_exists'),
            'name.required'              => __('funding_sequencing.name_required'),
            'name.string'                => __('funding_sequencing.name_string'),
            'name.max'                   => __('funding_sequencing.name_max'),
            'subtitle.required'          => __('funding_sequencing.subtitle_required'),
            'subtitle.string'            => __('funding_sequencing.subtitle_string'),
            'subtitle.max'               => __('funding_sequencing.subtitle_max'),
            'funding_group_id.required'  => __('funding_sequencing.funding_group_id_required'),
            'funding_group_id.exists'    => __('funding_sequencing.funding_group_id_exists'),
            'funding_type_id.required'   => __('funding_sequencing.funding_type_id_required'),
            'funding_type_id.exists'     => __('funding_sequencing.funding_type_id_exists'),
            'description.string'         => __('funding_sequencing.description_string'),
            'min_amount.required'        => __('funding_sequencing.min_amount_required'),
            'min_amount.numeric'         => __('funding_sequencing.min_amount_numeric'),
            'min_amount.gt'              => __('funding_sequencing.min_amount_gt'),
            'max_amount.required'        => __('funding_sequencing.max_amount_required'),
            'max_amount.numeric'         => __('funding_sequencing.max_amount_numeric'),
            'max_amount.gt'              => __('funding_sequencing.max_amount_gt'),
            'amount_range.max'           => __('funding_sequencing.amount_range_max'),
            'terms.max'                  => __('funding_sequencing.terms_max'),
            'icon.required'              => __('funding_sequencing.icon_required'),
            'icon.max'                   => __('funding_sequencing.icon_max'),
            'icon_color.max'             => __('funding_sequencing.icon_color_max'),
            'status.in'                  => __('funding_sequencing.status_in'),
            'sort_order.integer'         => __('funding_sequencing.sort_order_integer'),
            'action_url.max'             => __('funding_sequencing.action_url_max'),
            'readiness_factors.required' => __('funding_sequencing.readiness_factors_required'),
            'readiness_factors.min'      => __('funding_sequencing.readiness_factors_required'),
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
