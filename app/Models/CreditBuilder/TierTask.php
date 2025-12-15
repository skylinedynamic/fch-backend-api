<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TierTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tier_id',
        'title',
        'subtitle',
        'description',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function tier()
    {
        return $this->belongsTo(Tier::class);
    }
}
