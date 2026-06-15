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
            // Nested structure support
            'models' => 'nullable|array',
            'models.*.id' => 'required|integer',
            'models.*.slug' => 'nullable|string',
            'models.*.factors' => 'required|array',
            'models.*.factors.*.id' => 'required|integer',
            'models.*.factors.*.slug' => 'nullable|string',
            'models.*.factors.*.weight' => 'nullable|numeric|min:0|max:100',
            'models.*.factors.*.sort_order' => 'nullable|integer|min:0',

            'factors' => 'nullable|array',
            'factors.*.id' => 'required|integer',
            'factors.*.slug' => 'nullable|string',
            'factors.*.tiers' => 'required|array',
            'factors.*.tiers.*.id' => 'required|integer|exists:score_configurations,id',
            'factors.*.tiers.*.min_value' => 'nullable|numeric',
            'factors.*.tiers.*.max_value' => 'nullable|numeric',
            'factors.*.tiers.*.numeric_value' => 'nullable|numeric',
            'factors.*.tiers.*.conditions' => 'nullable|array',

            // Flat structure support (deprecated/backward compatibility)
            'model_factors' => 'nullable|array',
            'model_factors.*.score_model_id' => 'required|integer',
            'model_factors.*.score_factor_id' => 'required|integer',
            'model_factors.*.weight' => 'nullable|numeric|min:0|max:100',
            'model_factors.*.sort_order' => 'nullable|integer|min:0',

            'subscore_tiers' => 'nullable|array',
            'subscore_tiers.*.id' => 'required|integer|exists:score_configurations,id',
            'subscore_tiers.*.min_value' => 'nullable|numeric',
            'subscore_tiers.*.max_value' => 'nullable|numeric',
            'subscore_tiers.*.numeric_value' => 'nullable|numeric',
            'subscore_tiers.*.conditions' => 'nullable|array',

            // Shared structures
            'band_mappings' => 'nullable|array',
            'band_mappings.*.id' => 'required|integer|exists:score_configurations,id',
            'band_mappings.*.min_value' => 'nullable|numeric',
            'band_mappings.*.max_value' => 'nullable|numeric',
            'band_mappings.*.numeric_value' => 'nullable|numeric',

            'readiness_bands' => 'nullable|array',
            'readiness_bands.*.id' => 'required|integer|exists:score_configurations,id',
            'readiness_bands.*.min_value' => 'nullable|numeric',
            'readiness_bands.*.max_value' => 'nullable|numeric',

            'play_eligibility' => 'nullable|array',
            'play_eligibility.*.id' => 'required|integer|exists:score_configurations,id',
            'play_eligibility.*.numeric_value' => 'nullable|numeric',
        ];
    }
}
