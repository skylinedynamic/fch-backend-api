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

            'late_payment_modifiers' => 'nullable|array',
            'late_payment_modifiers.*.sort_order' => 'required|integer|min:1',
            'late_payment_modifiers.*.factor_slug' => 'required|string|max:255',
            'late_payment_modifiers.*.label' => 'nullable|string|max:255',
            'late_payment_modifiers.*.min_value' => 'required|numeric|min:1',
            'late_payment_modifiers.*.numeric_value' => 'required|numeric',

            'approval_probability' => 'nullable|array',
            'approval_probability.*.weight' => 'nullable|numeric|min:0|max:100',
            'approval_probability.*.order_sort' => 'nullable|integer|min:0',
            'approval_probability.*.factors' => 'nullable|array',
            'approval_probability.*.factors.*.min_value' => 'nullable|numeric',
            'approval_probability.*.factors.*.max_value' => 'nullable|numeric',
            'approval_probability.*.factors.*.sub_score' => 'nullable|numeric',
            'approval_probability.*.factors.*.sort_order' => 'nullable|integer|min:0',
            'approval_probability.*.factors.*.condition' => 'nullable|array',
            'approval_probability.*.factors.*.label' => 'nullable|string',

            'approval_odds' => 'nullable|array',
            'approval_odds.*.weight' => 'nullable|numeric|min:0|max:100',
            'approval_odds.*.order_sort' => 'nullable|integer|min:0',
            'approval_odds.*.factors' => 'nullable|array',
            'approval_odds.*.factors.*.min_value' => 'nullable|numeric',
            'approval_odds.*.factors.*.max_value' => 'nullable|numeric',
            'approval_odds.*.factors.*.sub_score' => 'nullable|numeric',
            'approval_odds.*.factors.*.sort_order' => 'nullable|integer|min:0',
            'approval_odds.*.factors.*.condition' => 'nullable|array',
            'approval_odds.*.factors.*.label' => 'nullable|string',

            'time_settings' => 'nullable|array',
            'time_settings.*.label' => 'nullable|string|max:255',
            'time_settings.*.start' => 'nullable|numeric',
            'time_settings.*.end' => 'nullable|numeric',
            'time_settings.*.sort_order' => 'required|integer|min:1',
        ];
    }
}
