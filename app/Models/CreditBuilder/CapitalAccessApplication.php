<?php

namespace App\Models\CreditBuilder;

use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapitalAccessApplication extends Model
{
    protected $table = 'capital_access_applications';

    protected $guarded = ['id'];

    protected $casts = [
        'credit_limit' => 'decimal:2',
    ];

    /**
     * Get the borrower who submitted the application.
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    /**
     * Get the associated capital access strategy.
     */
    public function capitalAccess(): BelongsTo
    {
        return $this->belongsTo(CapitalAccess::class, 'capital_access_id');
    }

    /**
     * Get the specific strategy step/sequence.
     */
    public function capitalAccessSequence(): BelongsTo
    {
        return $this->belongsTo(CapitalAccessSequence::class, 'capital_access_sequence_id');
    }
}
