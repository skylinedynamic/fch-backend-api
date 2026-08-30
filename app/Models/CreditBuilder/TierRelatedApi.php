<?php

namespace App\Models\CreditBuilder;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TierRelatedApi extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'task_related_apis'; // Note: Using task_related_apis table

    public const PROVIDER_EXPERIAN = 'Experian';
    public const PROVIDER_MICROBILT = 'MicroBilt';

    protected $fillable = [
        'name',
        'provider',
        'additional_info',
        'tier_id',
        'deleted_by',
    ];


    protected $casts = [
        'additional_info' => 'array',
    ];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'tier_id');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
