<?php

namespace App\Models\Funding;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundingType extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ───────────────────────────────────────────────────────
    const STATUS_ACTIVE   = 'Active';
    const STATUS_INACTIVE = 'Inactive';

    protected $fillable = [
        'name',
        'description',
        'status',
        'sort_order',
    ];

    public function sequencings(): HasMany
    {
        return $this->hasMany(FundingSequencing::class);
    }
}
