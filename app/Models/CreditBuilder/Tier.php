<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tier extends Model
{
    use HasFactory, SoftDeletes;

    public const FOUNDATION_CODE = 'T0';

    /** Tier 0, the foundation: by its code, else the first tier. */
    public static function foundation(): ?self
    {
        return static::where('code', self::FOUNDATION_CODE)->first()
            ?? static::orderBy('order', 'asc')->first();
    }

    protected $fillable = [
        'name',
        'title',
        'code',
        'subtitle',
        'description',
        'status',
        'has_vendor',
        'task_to_complete',
        'order',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function tasks()
    {
        return $this->hasMany(TierTask::class);
    }

    public function borrowers()
    {
        return $this->hasMany(\App\Models\Borrower\Borrower::class, 'current_tier');
    }

    public function vendors()
    {
        return $this->hasMany(CreditBuilderVendor::class, 'tier_id', 'id');
    }
}
