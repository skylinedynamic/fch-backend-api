<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPhase extends Model
{
    use HasFactory;

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
        return $this->belongsTo(Subscription::class);
    }
}
