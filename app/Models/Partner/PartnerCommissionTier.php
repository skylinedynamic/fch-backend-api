<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One funding-application count tier of a partner's commission: for its
 * clients' funded deals min_count..max_count (inclusive, max null = and up)
 * the partner's share of the success fee is `percentage`.
 */
class PartnerCommissionTier extends Model
{
    protected $table = 'partner_commission_tiers';

    protected $fillable = [
        'partner_id',
        'min_count',
        'max_count',
        'percentage',
        'sort_order',
    ];

    protected $casts = [
        'min_count' => 'integer',
        'max_count' => 'integer',
        'percentage' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
