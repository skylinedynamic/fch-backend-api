<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoreConfigItem extends Model
{
    protected $fillable = [
        'partner_id',
        'score_model_id',
        'category',
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
            'partner_id' => 'integer',
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'numeric_value' => 'decimal:2',
            'conditions' => 'json',
            'sort_order' => 'integer',
        ];
    }

    public const CATEGORY_SUBSCORE_TIER = 'subscore_tier';
    public const CATEGORY_BAND_MAPPING = 'band_mapping';
    public const CATEGORY_READINESS_BAND = 'readiness_band';
    public const CATEGORY_PLAY_ELIGIBILITY = 'play_eligibility';

    public function model(): BelongsTo
    {
        return $this->belongsTo(ScoreModel::class, 'score_model_id');
    }
}
