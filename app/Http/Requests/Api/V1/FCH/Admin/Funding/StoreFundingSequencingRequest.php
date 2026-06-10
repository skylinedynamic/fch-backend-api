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
            'icon'             => 'nullable|string|max:255',
            'icon_color'       => 'nullable|string|max:255',
            'status'           => 'nullable|string|in:Active,Inactive',
            'sort_order'       => 'nullable|integer',
            'action_url'       => 'nullable|string|max:255',
            'speed_to_funds'   => 'nullable|string|max:255',
            'uses'             => 'nullable|array',
            'uses.*'           => 'string|max:255',
            'qualify_reasons'  => 'nullable|array',
            'qualify_reasons.*'=> 'string|max:255',
            'readiness_factors'=> 'nullable|array',
            'readiness_factors.*'=> 'string|max:255',
            'missing_documents'=> 'nullable|array',
            'missing_documents.*'=> 'string|max:255',
            'min_fico'                       => ['nullable', 'integer'],
            'min_tib_months'                 => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'min_monthly_revenue'            => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'min_monthly_deposits'           => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'min_average_daily_balance'      => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'max_nsf_count'                  => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'max_negative_days'              => ['nullable', 'integer', 'min:0', 'digits_between:1,5'],
            'min_dscr'                       => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'allowed_states'                 => 'nullable|array',
            'allowed_states.*'               => 'string',
            'restricted_states'              => 'nullable|array',
            'restricted_states.*'            => 'string',
            'allowed_industries'             => 'nullable|array',
            'allowed_industries.*'           => 'string',
            'restricted_industries'          => 'nullable|array',
            'restricted_industries.*'        => 'string',
            'require_completed_application'  => 'nullable|boolean',
            'require_documents_uploaded'     => 'nullable|boolean',
            'allowed_documents'              => 'nullable|array|required_if:require_documents_uploaded,true,1,"1","true"',
            'allowed_documents.*'            => 'integer',
            'manual_review_allowed'          => 'nullable|boolean',
            'exception_allowed'              => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
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
            'min_amount.required'        => __('funding_sequencing.min_amount_required', [], 'en', 'Minimum amount is required'),
            'min_amount.numeric'         => __('funding_sequencing.min_amount_numeric', [], 'en', 'Minimum amount must be a number'),
            'min_amount.gt'              => __('funding_sequencing.min_amount_gt', [], 'en', 'Minimum amount must be greater than 0'),
            'max_amount.required'        => __('funding_sequencing.max_amount_required', [], 'en', 'Maximum amount is required'),
            'max_amount.numeric'         => __('funding_sequencing.max_amount_numeric', [], 'en', 'Maximum amount must be a number'),
            'max_amount.gt'              => __('funding_sequencing.max_amount_gt', [], 'en', 'Maximum amount must be greater than 0'),
            'amount_range.max'           => __('funding_sequencing.amount_range_max'),
            'terms.max'                  => __('funding_sequencing.terms_max'),
            'icon.max'                   => __('funding_sequencing.icon_max'),
            'icon_color.max'             => __('funding_sequencing.icon_color_max'),
            'status.in'                  => __('funding_sequencing.status_in'),
            'sort_order.integer'         => __('funding_sequencing.sort_order_integer'),
            'action_url.max'             => __('funding_sequencing.action_url_max'),
            'min_monthly_revenue.regex'      => __('funding_sequencing.min_monthly_revenue_regex'),
            'min_monthly_deposits.regex'     => __('funding_sequencing.min_monthly_deposits_regex'),
            'min_average_daily_balance.regex'=> __('funding_sequencing.min_average_daily_balance_regex'),
            'min_dscr.regex'                 => __('funding_sequencing.min_dscr_regex'),
            'min_fico.required'              => __('funding_sequencing.min_fico_required'),
            'min_fico.integer'               => __('funding_sequencing.min_fico_integer'),
            'allowed_documents.required_if'  => __('funding_sequencing.allowed_documents_required_if'),
        ];
    }
}
