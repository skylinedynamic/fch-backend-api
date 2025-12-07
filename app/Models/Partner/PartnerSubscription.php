<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class PartnerSubscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'partner_id',
        'partner_type',
        'enrollment_type',
        'monthly_platform_fee',
        'first_reg_amount',
        'first_reg_date',
        'installment_type',
        'first_reg_payment',
        'first_reg_payment_2',
        'total_paid_amount',
        'subscription_start_date',
        'subscription_end_date',
        'expiry_date',
        'termination_date',
        'status',
    ];

    protected $casts = [
        'first_reg_date' => 'date',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'expiry_date' => 'date',
        'termination_date' => 'date',
        'monthly_platform_fee' => 'decimal:2',
        'first_reg_amount' => 'decimal:2',
        'installment_type' => 'decimal:2',
        'first_reg_payment' => 'decimal:2',
        'first_reg_payment_2' => 'decimal:2',
        'total_paid_amount' => 'decimal:2',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
