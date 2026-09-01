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
        'total_installment_count_upto',
        'total_seat_count',
        'group',
        'total_amount',
        'platform_fee',
        'currency',
        'currency_symbol',
        'conversion_rate',
        'status',
    ];

    protected $casts = [
        'total_installment_count_upto' => 'integer',
        'total_seat_count' => 'integer',
        'total_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'conversion_rate' => 'decimal:4',
        'status' => 'boolean',
    ];


    const ENROLLMENT_TYPE_ONE_TIME_PAYMENT = 'One Time Payment';
    const ENROLLMENT_TYPE_INSTALLMENT = 'Installment';
    const ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT = 'Fixed Enrollment';
    const ENROLLMENT_TYPE_MONTHLY_SEAT_FEE = 'Monthly';


    const ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG = 'one_time_payment';
    const ENROLLMENT_TYPE_INSTALLMENT_SLUG = 'installment';
    const ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG = 'fixed_enrollment';
    const ENROLLMENT_TYPE_MONTHLY_SEAT_FEE_SLUG = 'monthly';


    //subscription plan group
    const SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT = 'partner_enrollment';
    const SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT = 'borrower_enrollment';
    const SUBSCRIPTION_PLAN_BORROWER_FEE = 'borrower_fee';
    const SUBSCRIPTION_PLAN_USER_SEAT = 'user_seat';

    /**
     * Determine whether this plan is an installment plan.
     */
    public function isInstallmentPlan(): bool
    {
        $slug = strtolower((string) $this->enrollment_type_slug);

        return strtolower((string) $this->enrollment_type) === self::ENROLLMENT_TYPE_INSTALLMENT_SLUG;
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
