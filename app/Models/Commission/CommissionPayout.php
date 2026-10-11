<?php

namespace App\Models\Commission;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A payout made from a commission — to a partner, or to the user assigned to a
 * host-partner client — as it was when paid (see CommissionPayoutService). The
 * amount and payment method are on its invoice and payment.
 */
class CommissionPayout extends Model
{
    /** Who can be paid, by the type shown to the API. */
    public const PAYEE_TYPES = [
        'partner' => Partner::class,
        'user' => User::class,
    ];

    protected $fillable = [
        'commission_id',
        'entity_type',
        'entity_id',
        'name',
        'email',
        'payout_type',
        'invoice_id',
        'payment_id',
        'currency',
        'currency_symbol',
        'conversion_rate',
        'paid_at',
        'created_by',
    ];

    protected $casts = [
        'conversion_rate' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** 'partner' or 'user', as the API names who was paid. */
    public function payeeType(): string
    {
        return array_search($this->entity_type, self::PAYEE_TYPES, true) ?: 'user';
    }
}
