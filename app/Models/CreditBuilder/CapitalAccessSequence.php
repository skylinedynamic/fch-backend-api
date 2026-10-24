<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Model;

class CapitalAccessSequence extends Model
{
    protected $table = 'capital_access_sequences';

    protected $guarded = ['id'];

    protected $casts = [
        'what_you_need'                 => 'array',
        'sorting_order'                 => 'integer',
        'est_limit_min'                 => 'integer',
        'est_limit_max'                 => 'integer',
        'minimum_fico_requirement'      => 'decimal:2',
        'maximum_utilization_threshold' => 'decimal:2',
        'difficulty_modifier'           => 'decimal:2',
    ];

    /**
     * The credit account card for this sequence step.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    /**
     * The steps a borrower is offered: those whose credit account is available
     * - not inactivated or deleted by the host (BUG-042).
     */
    public function scopeOffered($query)
    {
        return $query->whereHas('creditAccount', fn ($q) => $q->available());
    }

    public function creditAccount()
    {
        return $this->belongsTo(CreditAccount::class, 'credit_account_id');
    }

    /**
     * The capital access strategy this sequence belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function capitalAccess()
    {
        return $this->belongsTo(CapitalAccess::class, 'capital_access_id');
    }
}
