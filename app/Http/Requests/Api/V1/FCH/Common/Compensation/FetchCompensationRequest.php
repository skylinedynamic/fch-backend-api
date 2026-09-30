<?php

namespace App\Http\Requests\Api\V1\FCH\Common\Compensation;

use App\Http\Requests\Api\V1\BaseApiRequest;
use App\Models\Compensation\CompensationSetting;
use Illuminate\Validation\Rule;

class FetchCompensationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', Rule::in(array_keys(CompensationSetting::ENTITY_TYPES))],
            'entity_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'entity_type.required' => __('compensation.entity_type_required'),
            'entity_type.in' => __('compensation.entity_type_invalid'),
            'entity_id.required' => __('compensation.entity_id_required'),
        ];
    }
}
