<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScoreModel extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'output_min',
        'output_max',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'output_min' => 'decimal:2',
            'output_max' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const SLUG_FUNDING_READINESS = 'funding_readiness';
    public const SLUG_CARD_APPROVAL_ODDS = 'card_approval_odds';

    public function factors(): BelongsToMany
    {
        return $this->belongsToMany(ScoreFactor::class, 'score_model_factor')
            ->withPivot('weight', 'sort_order')
            ->orderByPivot('sort_order');
    }

    public function modelFactors(): HasMany
    {
        return $this->hasMany(ScoreModelFactor::class);
    }

    public function configItems(): HasMany
    {
        return $this->hasMany(ScoreConfigItem::class);
    }

    public function bandMappings(): HasMany
    {
        return $this->hasMany(ScoreConfigItem::class)
            ->where('category', ScoreConfigItem::CATEGORY_BAND_MAPPING);
    }
}
