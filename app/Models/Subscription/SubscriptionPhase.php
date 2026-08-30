<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Subscription\Subscription;

class SubscriptionPhase extends Model
{
    use HasFactory, SoftDeletes;

    const PHASE_ENROLLMENT = 'enrollment';
    const PHASE_PLATFORM_FEE = 'platform_fee';

    protected $fillable = [
        'subscription_id',
        'phase',
        'installments_paid',
        'enrollment_completed_at',
        'next_installment_date',
        'next_platform_fee_date',
    ];

    protected $casts = [
        'enrollment_completed_at' => 'datetime',
        'next_installment_date' => 'date',
        'next_platform_fee_date' => 'date',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }
}
