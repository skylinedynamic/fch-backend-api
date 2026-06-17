<?php

namespace App\Http\Requests\Api\V1\FCH\Admin\ScoreConfiguration;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdateScoreConfigurationRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'funding_readiness' => 'nullable|array',
            'funding_readiness.*.weight' => 'nullable|numeric|min:0|max:100',
            'funding_readiness.*.order_sort' => 'nullable|integer|min:0',
            'funding_readiness.*.factors' => 'nullable|array',
            'funding_readiness.*.factors.*.min_value' => 'nullable|numeric',
            'funding_readiness.*.factors.*.max_value' => 'nullable|numeric',
            'funding_readiness.*.factors.*.sub_score' => 'nullable|numeric',
            'funding_readiness.*.factors.*.sort_order' => 'nullable|integer|min:0',
            'funding_readiness.*.factors.*.condition' => 'nullable|array',
            'funding_readiness.*.factors.*.label' => 'nullable|string',

            'readiness_bands' => 'nullable|array',
            'readiness_bands.*.sort_order' => 'required|integer|min:1',
            'readiness_bands.*.label' => 'nullable|string|max:255',
            'readiness_bands.*.color' => 'nullable|string|max:20',
            'readiness_bands.*.min_value' => 'nullable|numeric',
            'readiness_bands.*.max_value' => 'nullable|numeric',
        ];
    }
}
