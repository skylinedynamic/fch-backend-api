<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\Funding;

use App\Http\Requests\Api\V1\BaseApiRequest;

class DeleteFundingSequencingVisibilityRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'visibility_ids' => 'required|array',
            'visibility_ids.*' => 'integer|exists:lender_visibilities,id'
        ];
    }

    public function messages(): array
    {
        return [
            'visibility_ids.required' => __('funding_sequencing.visibility_ids_required'),
            'visibility_ids.array'    => __('funding_sequencing.visibility_ids_array'),
            'visibility_ids.*.integer'=> __('funding_sequencing.visibility_ids_integer'),
            'visibility_ids.*.exists' => __('funding_sequencing.visibility_ids_exists'),
        ];
    }
}
