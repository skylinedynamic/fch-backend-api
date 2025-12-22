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
        'subtitle',
        'description',
        'status',
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
}
