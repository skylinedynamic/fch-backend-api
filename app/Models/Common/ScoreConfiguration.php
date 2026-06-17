<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Model;

class ScoreConfiguration extends Model
{
    protected $table = 'score_configurations';

    protected $fillable = [
        'category',
        'model_slug',
        'factor_slug',
        'label',
        'min_value',
        'max_value',
        'numeric_value',
        'conditions',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'numeric_value' => 'decimal:2',
            'conditions' => 'json',
            'sort_order' => 'integer',
        ];
    }

    public const CATEGORY_MODEL_WEIGHT = 'model_weight';
    public const CATEGORY_SUBSCORE_TIER = 'subscore_tier';
    public const CATEGORY_READINESS_BAND = 'readiness_band';
}
