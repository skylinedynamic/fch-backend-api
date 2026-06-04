<?php

namespace App\Models\Funding;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundingSequencing extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ───────────────────────────────────────────────────────
    const STATUS_ACTIVE   = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $fillable = [
        'funding_group_id',
        'funding_type_id',
        'name',
        'subtitle',
        'description',
        'amount_range',
        'terms',
        'icon',
        'icon_color',
        'status',
        'sort_order',
        'action_url',
        'speed_to_funds',
        'uses',
        'qualify_reasons',
        'missing_documents',
        'readiness_factors',
        'eligibility_status',
        'eligibility_label',
    ];

    protected $casts = [
        'qualify_reasons' => 'array',
        'missing_documents' => 'array',
        'readiness_factors' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FundingGroup::class, 'funding_group_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(FundingType::class, 'funding_type_id');
    }

    public function scopeSearch($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', '%' . $term . '%')
              ->orWhere('subtitle', 'like', '%' . $term . '%')
              ->orWhere('description', 'like', '%' . $term . '%')
              ->orWhere('amount_range', 'like', '%' . $term . '%')
              ->orWhere('terms', 'like', '%' . $term . '%')
              ->orWhere('status', 'like', '%' . $term . '%')
              ->orWhereHas('group', function ($q) use ($term) {
                  $q->where('name', 'like', '%' . $term . '%');
              })
              ->orWhereHas('type', function ($q) use ($term) {
                  $q->where('name', 'like', '%' . $term . '%');
              });
        });
    }
}
