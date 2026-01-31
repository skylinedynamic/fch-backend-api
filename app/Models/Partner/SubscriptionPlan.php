<?php

namespace App\Models\Partner;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'partner_type',
        'partner_type_slug',
        'enrollment_type',
        'enrollment_type_slug',
        'payment_amount',
        'installments_count',
        'total_installments_count',
        'total_amount',
        'platform_fee',
        'currency',
        'currency_symbol',
        'currency_rate',
        'status',
    ];

    protected $casts = [
        'installments_count' => 'integer',
        'total_installments_count' => 'integer',
        'payment_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'currency_rate' => 'decimal:4',
        'status' => 'boolean',
    ];
}
