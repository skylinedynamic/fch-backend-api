<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartnerSubscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'partner_id',
        'partner_url',
        'billing_term',
        'first_reg_date',
        'first_reg_amount',
        'first_payment_amount',
        'first_reg_amount_2',
        'recurring_amount',
        'total_amount',
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
        'first_reg_amount' => 'decimal:2',
        'first_payment_amount' => 'decimal:2',
        'first_reg_amount_2' => 'decimal:2',
        'recurring_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
