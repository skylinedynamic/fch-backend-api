<?php

namespace App\Models\Subscription;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_INACTIVE = 'Inactive';
    const STATUS_SUSPENDED = 'Suspended';
    const STATUS_CANCELLED = 'Cancelled';
    const STATUS_PENDING = 'Pending';

    protected $table = 'subscriptions';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'subscription_plan_id',
        'partner_type',
        'enrollment_type',
        'monthly_platform_fee',
        'total_subscription_amount',
        'payable_amount',
        'first_payment_date',
        'platform_fee_start_date',
        // 'next_installment_date',
        'installment_type',
        'subscription_start_date',
        'subscription_end_date',
        'status',
    ];

    protected $casts = [
        'first_payment_date' => 'date',
        'platform_fee_start_date' => 'date',
        // 'next_installment_date' => 'date',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'monthly_platform_fee' => 'decimal:2',
        'total_subscription_amount' => 'decimal:2',
        'payable_amount' => 'decimal:2',
        'installment_type' => 'integer',
    ];

    public function partner()
    {
        // Note: usage of this relation assumes the subscription IS a partner subscription.
        // If entity_type matches Borrower, this will still return a Partner if IDs collide.
        // Strict usage should check entity_type or use the 'entity' relation.
        return $this->belongsTo(Partner::class, 'entity_id');
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class, 'entity_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'entity_id');
    }

    public function entity()
    {
        return $this->morphTo();
    }

    public function partnerPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function phases()
    {
        return $this->hasMany(SubscriptionPhase::class);
    }

    public function enrollmentPhase()
    {
        return $this->hasOne(SubscriptionPhase::class)->where('phase', SubscriptionPhase::PHASE_ENROLLMENT);
    }

    public function platformFeePhase()
    {
        return $this->hasOne(SubscriptionPhase::class)->where('phase', SubscriptionPhase::PHASE_PLATFORM_FEE);
    }

    /**
     * Scope: apply text search across common subscription fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('status', 'like', "%{$search}%")
                ->orWhere('enrollment_type', 'like', "%{$search}%")
                ->orWhereHas('partner', function ($pq) use ($search) {
                    $pq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('borrower', function ($bq) use ($search) {
                    $bq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }
}
