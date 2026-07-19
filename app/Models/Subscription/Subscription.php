<?php

namespace App\Models\Subscription;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_EXPIRED = 'Expired';
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
        'is_custom',
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
        'is_custom' => 'boolean',
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

    public function allPhases()
    {
        return $this->hasMany(SubscriptionPhase::class);
    }

    public function phases()
    {
        return $this->hasOne(SubscriptionPhase::class)->latestOfMany();
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
            $q->where('subscriptions.status', 'like', "%{$search}%")
                ->orWhere('subscriptions.enrollment_type', 'like', "%{$search}%")
                ->orWhere('subscriptions.payable_amount', 'like', "%{$search}%")
                ->orWhereRaw("DATE_FORMAT(subscriptions.created_at, '%Y-%m-%d %h:%i %p') LIKE ?", ["%{$search}%"])
                ->orWhereHas('partner', function ($pq) use ($search) {
                    $pq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                ->orWhereHas('borrower', function ($bq) use ($search) {
                    $bq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere(DB::raw("CONCAT(borrowers.first_name, ' ', borrowers.last_name)"), 'like', "%{$search}%");
                })
                ->orWhereHas('enrollmentPhase', function ($sq) use ($search) {
                    $sq->where('next_installment_date', 'like', "%{$search}%");
                })
                ->orWhereHas('platformFeePhase', function ($sq) use ($search) {
                    $sq->where('next_platform_fee_date', 'like', "%{$search}%");
                });
        });
    }
    /**
     * Scope: join partners table.
     */
    public function scopeWithPartner($query)
    {
        return $query->leftJoin('partners', function ($join) {
            $join->on('subscriptions.entity_id', '=', 'partners.id')
                ->where('subscriptions.entity_type', '=', Partner::class);
        })->addSelect('partners.full_name as partner_name');
    }

    /**
     * Scope: join enrollment phase.
     */
    public function scopeWithEnrollmentPhase($query)
    {
        return $query->leftJoin('subscription_phases as ep', function ($join) {
            $join->on('subscriptions.id', '=', 'ep.subscription_id')
                ->where('ep.phase', '=', SubscriptionPhase::PHASE_ENROLLMENT);
        })->addSelect('ep.next_installment_date');
    }

    /**
     * Scope: join platform fee phase.
     */
    public function scopeWithPlatformFeePhase($query)
    {
        return $query->leftJoin('subscription_phases as pfp', function ($join) {
            $join->on('subscriptions.id', '=', 'pfp.subscription_id')
                ->where('pfp.phase', '=', SubscriptionPhase::PHASE_PLATFORM_FEE);
        })->addSelect('pfp.next_platform_fee_date');
    }

    /**
     * Scope: join borrower table.
     */
    public function scopeWithBorrower($query)
    {
        return $query->leftJoin('borrowers', function ($join) {
            $join->on('subscriptions.entity_id', '=', 'borrowers.id')
                ->where('subscriptions.entity_type', '=', Borrower::class);
        })->addSelect(
                'borrowers.first_name',
                'borrowers.last_name'
                // We can resolve full name in concatenation or view
            );
    }

    /**
     * Accessor: human-readable entity name (partner/borrower/employee).
     */
    public function getEntityNameAttribute(): ?string
    {
        if (!$this->entity_type) {
            return null;
        }

        $map = [
            Partner::class => User::PARTNER,
            Borrower::class => User::BORROWER,
            \App\Models\Employee\Employee::class => User::EMPLOYEE,
        ];

        return $map[$this->entity_type] ?? strtolower(class_basename($this->entity_type));
    }
}
