<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateFundingSequencingDisplayRequest extends BaseApiRequest
{
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

    public function rules(): array
    {
        return [
            'name'             => 'sometimes|required|string|max:255',
            'subtitle'         => 'sometimes|required|string|max:255',
            'funding_group_id' => 'sometimes|required|exists:funding_groups,id',
            'funding_type_id'  => 'sometimes|required|exists:funding_types,id',
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
            'readiness_factors'=> 'nullable|array',
            'readiness_factors.*'=> 'string|max:255',
            'missing_documents'=> 'nullable|array',
            'missing_documents.*'=> 'string|max:255',
            'require_completed_application' => 'nullable|boolean',
            'require_documents_uploaded'    => 'nullable|boolean',
            'allowed_documents'             => 'required_if:require_documents_uploaded,true,1|nullable|array',
            'allowed_documents.*'           => 'integer',
            'manual_review_allowed'         => 'nullable|boolean',
            'exception_allowed'             => 'nullable|boolean',
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
            'icon.required'              => __('funding_sequencing.icon_required'),
            'icon.max'                   => __('funding_sequencing.icon_max'),
            'icon_color.max'             => __('funding_sequencing.icon_color_max'),
            'status.in'                  => __('funding_sequencing.status_in'),
            'sort_order.integer'         => __('funding_sequencing.sort_order_integer'),
            'action_url.max'             => __('funding_sequencing.action_url_max'),
            'readiness_factors.required'     => __('funding_sequencing.readiness_factors_required'),
            'allowed_documents.required_if'  => __('funding_sequencing.allowed_documents_required_if'),
            'require_completed_application.accepted_if' => __('funding_sequencing.require_completed_application_accepted_if'),
        ];
    }
}
