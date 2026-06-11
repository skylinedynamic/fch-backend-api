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
            'model_factors' => 'nullable|array',
            'model_factors.*.score_model_id' => 'required|integer|exists:score_models,id',
            'model_factors.*.score_factor_id' => 'required|integer|exists:score_factors,id',
            'model_factors.*.weight' => 'nullable|numeric|min:0|max:100',
            'model_factors.*.sort_order' => 'nullable|integer|min:0',

            'subscore_tiers' => 'nullable|array',
            'subscore_tiers.*.id' => 'required|integer|exists:score_config_items,id',

            'band_mappings' => 'nullable|array',
            'band_mappings.*.id' => 'required|integer|exists:score_config_items,id',

            'readiness_bands' => 'nullable|array',
            'readiness_bands.*.id' => 'required|integer|exists:score_config_items,id',

            'play_eligibility' => 'nullable|array',
            'play_eligibility.*.id' => 'required|integer|exists:score_config_items,id',
        ];
    }
}
