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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'             => 'sometimes|required|string|max:255',
            'subtitle'         => 'sometimes|required|string|max:255',
            'funding_group_id' => 'sometimes|required|exists:funding_groups,id',
            'funding_type_id'  => 'sometimes|required|exists:funding_types,id',
            'description'      => 'nullable|string',
            'amount_range'     => 'nullable|string|max:255',
            'terms'            => 'nullable|string|max:255',
            'icon'             => 'nullable|string|max:255',
            'icon_color'       => 'nullable|string|max:255',
            'status'           => 'nullable|string|in:Active,Inactive',
            'sort_order'       => 'nullable|integer',
            'action_url'       => 'nullable|string|max:255',
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
            'amount_range.max'           => __('funding_sequencing.amount_range_max'),
            'terms.max'                  => __('funding_sequencing.terms_max'),
            'icon.max'                   => __('funding_sequencing.icon_max'),
            'icon_color.max'             => __('funding_sequencing.icon_color_max'),
            'status.in'                  => __('funding_sequencing.status_in'),
            'sort_order.integer'         => __('funding_sequencing.sort_order_integer'),
            'action_url.max'             => __('funding_sequencing.action_url_max'),
        ];
    }
}
