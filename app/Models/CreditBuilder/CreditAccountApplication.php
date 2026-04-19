<?php

namespace App\Models\CreditBuilder;

use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditAccountApplication extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_APPROVED,
    ];

    public const STATUS_REQUESTED = 'Requested';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PROCESSING = 'Processing';
    public const STATUS_APPROVED = 'Approved';

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(CreditAccount::class);
    }
}
