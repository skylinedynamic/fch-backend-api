<?php

namespace App\Models\CreditBuilder;

use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerCapitalAccessPlay extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
    ];

    protected $table = 'borrower_capital_access_plays';

    protected $guarded = ['id'];

    /**
     * Get the borrower who is running the play.
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    /**
     * Get the capital access strategy.
     */
    public function capitalAccess(): BelongsTo
    {
        return $this->belongsTo(CapitalAccess::class, 'capital_access_id');
    }
}
