<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One funding-application count tier of a partner's commission: for its
 * clients' funded deals min_count..max_count (inclusive, max null = and up)
 * the partner gets, by the tier's commission_type, `percentage` of the
 * success fee or a flat `amount` per deal; the other value is null.
 */
class PartnerCommissionTier extends Model
{
    /** A share (%) of the success fee. */
    public const TYPE_PERCENTAGE = 'percentage';

    /** A fixed amount ($) per deal. */
    public const TYPE_FLAT = 'flat';

    public const TYPES = [self::TYPE_PERCENTAGE, self::TYPE_FLAT];

    /** The range of a flat amount per deal ($); the column is decimal(10,2). */
    public const AMOUNT_MIN = 0;
    public const AMOUNT_MAX = 99999999.99;

    protected $table = 'partner_commission_tiers';

    protected $fillable = [
        'partner_id',
        'min_count',
        'max_count',
        'commission_type',
        'percentage',
        'amount',
        'sort_order',
    ];

    protected $attributes = [
        'commission_type' => self::TYPE_PERCENTAGE,
    ];

    protected $casts = [
        'min_count' => 'integer',
        'max_count' => 'integer',
        'percentage' => 'decimal:2',
        'amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function isFlat(): bool
    {
        return $this->commission_type === self::TYPE_FLAT;
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
