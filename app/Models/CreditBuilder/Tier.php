<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tier extends Model
{
    use HasFactory, SoftDeletes;

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
