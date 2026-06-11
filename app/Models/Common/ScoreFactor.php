<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScoreFactor extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'unit',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const SLUG_FICO = 'fico';
    public const SLUG_UTILIZATION = 'utilization';
    public const SLUG_TIME_IN_BUSINESS = 'time_in_business';
    public const SLUG_REVENUE_STRENGTH = 'revenue_strength';
    public const SLUG_TRADELINE_DEPTH = 'tradeline_depth';
    public const SLUG_BANKING_RELATIONSHIPS = 'banking_relationships';
    public const SLUG_RECENT_INQUIRIES = 'recent_inquiries';

    public function models(): BelongsToMany
    {
        return $this->belongsToMany(ScoreModel::class, 'score_model_factor')
            ->withPivot('weight', 'sort_order')
            ->orderByPivot('sort_order');
    }

    public function modelFactors(): HasMany
    {
        return $this->hasMany(ScoreModelFactor::class);
    }

    public function subscoreTiers(): HasMany
    {
        return $this->hasMany(ScoreConfigItem::class, 'factor_slug', 'slug')
            ->where('category', ScoreConfigItem::CATEGORY_SUBSCORE_TIER)
            ->orderBy('sort_order');
    }
}
