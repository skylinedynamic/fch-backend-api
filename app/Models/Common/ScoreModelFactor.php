<?php

namespace App\Models\Common;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoreModelFactor extends Model
{
    protected $table = 'score_model_factor';

    protected $fillable = [
        'score_model_id',
        'score_factor_id',
        'weight',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(ScoreModel::class, 'score_model_id');
    }

    public function factor(): BelongsTo
    {
        return $this->belongsTo(ScoreFactor::class, 'score_factor_id');
    }
}
