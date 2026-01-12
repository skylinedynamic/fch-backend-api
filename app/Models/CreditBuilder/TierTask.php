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
        'logo',
        'description',
        'status',
        'url',
        'button_title',
        'order',
        'is_mandatory',
        'manual_verification',
        'related_api_id',
        'vendor_id',
        'estimated_completion_days',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function tier()
    {
        return $this->belongsTo(Tier::class);
    }

    public function borrowerTierTasks()
    {
        return $this->hasMany(BorrowerTaskVerificationRequest::class, 'task_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(CreditBuilderVendor::class);
    }
}
