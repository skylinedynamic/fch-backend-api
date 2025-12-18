<?php

namespace App\Models\CreditBuilder;

use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BorrowerTierTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tier_id',
        'tier_task_id',
        'borrower_id',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public const PENDING = 'pending';
    public const COMPLETED = 'completed';

    public const STATUS = [
        self::PENDING => self::PENDING,
        self::COMPLETED => self::COMPLETED,
    ];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TierTask::class, 'tier_task_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }
}
