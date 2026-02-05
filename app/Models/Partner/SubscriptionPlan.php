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
        'entity_type',
        'is_partner_paying',
        'payment_amount',
        'total_installments_count',
        'subscription_period_month',
        'total_amount',
        'platform_fee',
        'currency',
        'currency_symbol',
        'conversion_rate',
        'status',
    ];

    protected $casts = [
        'total_installments_count' => 'integer',
        'subscription_period_month' => 'integer',
        'payment_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'conversion_rate' => 'decimal:4',
        'status' => 'boolean',
    ];
}
