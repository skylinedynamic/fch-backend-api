<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class StoreFundingSequencingRequest extends BaseApiRequest
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
            'description'      => 'nullable|string',
            'min_amount'       => 'required|numeric|gt:0',
            'max_amount'       => 'required|numeric|gt:0',
            'amount_range'     => 'nullable|string|max:255',
            'terms'            => 'nullable|string|max:255',
            'icon'             => 'required|string|max:255',
            'icon_color'       => 'nullable|string|max:255',
            'status'           => 'required|string|in:Active,Inactive',
            'sort_order'       => 'nullable|integer',
            'action_url'       => 'nullable|string|max:255',
            'speed_to_funds'   => 'nullable|string|max:255',
            'uses'             => 'nullable|array',
            'uses.*'           => 'string|max:255',
            'readiness_factors'=> 'required|array|min:1',
            'readiness_factors.*'=> 'string|max:255',
            'missing_documents'=> 'nullable|array',
            'missing_documents.*'=> 'string|max:255',
            'require_completed_application' => 'nullable|boolean',
            'require_documents_uploaded'    => 'nullable|boolean',
            'allowed_documents'             => 'required_if:require_documents_uploaded,true,1|nullable|array',
            'allowed_documents.*'           => 'integer',
            'manual_review_allowed'         => 'nullable|boolean',
            'exception_allowed'             => 'nullable|boolean',
            'lender_visibilities'                        => 'nullable|array',
            'lender_visibilities.*.lender_id'            => 'required|exists:lenders,id',
            'lender_visibilities.*.lender_product_category_id' => 'required|integer|exists:lender_product_categories,id',
            'lender_visibilities.*.min_fico'             => ['required', 'integer'],
            'lender_visibilities.*.min_tib_months'       => ['required', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.min_monthly_revenue'  => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_monthly_deposits' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_avg_deposits'     => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_average_daily_balance' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lender_visibilities.*.min_deposit_count'    => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.max_nsf_count'=> ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'lender_visibilities.*.max_negative_days'=> ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
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
            'lender_visibilities.*.lender_product_category_id.required' => 'The product category is required.',
            'lender_visibilities.*.min_monthly_revenue.required'      => __('funding_sequencing.min_monthly_revenue_required'),
            'lender_visibilities.*.min_monthly_revenue.regex'      => __('funding_sequencing.min_monthly_revenue_regex'),
            'lender_visibilities.*.min_monthly_deposits.required'     => __('funding_sequencing.min_monthly_deposits_required'),
            'lender_visibilities.*.min_monthly_deposits.regex'     => __('funding_sequencing.min_monthly_deposits_regex'),
            'lender_visibilities.*.min_average_daily_balance.required'=> __('funding_sequencing.min_average_daily_balance_required'),
            'lender_visibilities.*.min_average_daily_balance.regex'=> __('funding_sequencing.min_average_daily_balance_regex'),
            'lender_visibilities.*.min_dscr.regex'                 => __('funding_sequencing.min_dscr_regex'),
            'lender_visibilities.*.min_tib_months.required'        => __('funding_sequencing.min_tib_months_required'),
            'lender_visibilities.*.min_fico.required'              => __('funding_sequencing.min_fico_required'),
            'lender_visibilities.*.min_fico.integer'               => __('funding_sequencing.min_fico_integer'),
            'lender_visibilities.*.min_deposit_count.required'     => __('funding_sequencing.min_deposit_count_required'),
            'lender_visibilities.*.max_nsf_count.required'         => __('funding_sequencing.max_nsf_count_required'),
            'lender_visibilities.*.max_negative_days.required'     => __('funding_sequencing.max_negative_days_required'),
            'allowed_documents.required_if'  => __('funding_sequencing.allowed_documents_required_if'),
            'require_completed_application.accepted_if' => __('funding_sequencing.require_completed_application_accepted_if'),
            'lender_visibilities.*.lender_id.distinct'   => __('funding_sequencing.lender_id_distinct'),
        ];
    }
}
