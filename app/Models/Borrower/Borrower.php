<?php

namespace App\Models\Borrower;

use App\Models\CreditBuilder\Tier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class Borrower extends Model
{
    use HasFactory, SoftDeletes;

    protected string $guard_name = 'api';
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'borrowers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the borrower.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(\App\Models\User\User::class, 'entity');
    }

    /**
     * Get the partner that owns the borrower.
     */
    public function partner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class, 'partner_id');
    }

    /**
     * Get the borrower's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Get the assigned user for the borrower.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User\User::class, 'assign_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'current_tier');
    }
}