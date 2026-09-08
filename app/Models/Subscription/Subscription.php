<?php

namespace App\Models\Subscription;

use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * subscription_end_date vs expected_end_date:
 *  - subscription_end_date: paid through this date. Only moves on an actual
 *    successful payment (handleOneTimePayment / handleInstallmentPayment /
 *    handleUserSeatPayment in SubscriptionService).
 *  - expected_end_date: the scheduling field. generateDueInvoices() and
 *    createInvoiceIfDue() key off THIS, not subscription_end_date. It advances
 *    both on payment and on a missed-cycle rollover (invoice expired, next
 *    cycle generated) — so it can run ahead of subscription_end_date.
 *  The gap between the two is, by construction, how far behind a subscription
 *  actually is on real payment.
 */
class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'Active';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_CANCELLED = 'Cancelled';
    const STATUS_EXPIRED = 'Expired';
    const STATUS_PENDING = 'Pending';
    const STATUS_PAST_DUE = 'Past Due';

    const ENROLLMENT_TYPE_PAYMENT_PLAN = 'Payment Plan';
    const ENROLLMENT_TYPE_MONTHLY = 'Monthly';


    const PHASE_ENROLLMENT = 'enrollment';
    const PHASE_INSTALLMENT = 'installment';
    const PHASE_PLATFORM_FEE = 'platform_fee';
    const PHASE_USER_SEAT = 'user_seat';
    const PHASE_COMPLETED = 'completed';

    protected $table = 'subscriptions';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'subscription_plan_id',
        'partner_type',
        'enrollment_type',
        'monthly_platform_fee',
        'total_subscription_amount',
        'subscription_amount_per_month',
        'borrower_fee_amount',
        'payable_amount',
        'down_payment',
        'first_payment_date',
        'installment_type',
        'installment_count',
        'subscription_start_date',
        'subscription_end_date',
        'expected_end_date',
        'billing_paused_at',
        'current_cycle_invoice_id',
        'status',
        'is_custom',
        'current_phase',
        'installments_paid',
        'enrollment_completed_at',
        'user_seats',
        'per_seat_amount',
    ];

    protected $casts = [
        // Calendar dates, not instants: stored as DATE and emitted verbatim via
        // toDateOnly(). Never run these through toTimezone() - shifting a
        // date-only value into the viewer's zone reads it as midnight UTC and
        // hands anyone behind UTC the previous day.
        'first_payment_date' => 'date',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'expected_end_date' => 'date',
        'billing_paused_at' => 'datetime',
        'current_cycle_invoice_id' => 'integer',
        'monthly_platform_fee' => 'decimal:2',
        'total_subscription_amount' => 'decimal:2',
        'subscription_amount_per_month' => 'decimal:2',
        'borrower_fee_amount' => 'decimal:2',
        'payable_amount' => 'decimal:2',
        'down_payment' => 'decimal:2',
        'installment_type' => 'integer',
        'installment_count' => 'integer',
        'is_custom' => 'boolean',
        'current_phase' => 'string',
        'installments_paid' => 'integer',
        'enrollment_completed_at' => 'datetime',
        'user_seats' => 'integer',
        'per_seat_amount' => 'decimal:2',
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

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'entity_id');
    }

    public function entity()
    {
        return $this->morphTo();
    }

    public function partnerPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function currentCycleInvoice()
    {
        return $this->belongsTo(\App\Models\Invoice\Invoice::class, 'current_cycle_invoice_id');
    }

    public function invoices()
    {
        return $this->hasMany(\App\Models\Invoice\Invoice::class, 'subscription_id');
    }

    /**
     * Invoices still owed. Excludes Expired — a rolled-past cycle is not a
     * collectable debt, it is a record of a cycle that was missed.
     */
    public function unpaidInvoices()
    {
        return $this->hasMany(\App\Models\Invoice\Invoice::class, 'subscription_id')
            ->where('status', \App\Models\Invoice\Invoice::STATUS_UNPAID);
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
                ->orWhere('subscriptions.current_phase', 'like', "%{$search}%")
                ->orWhere('subscriptions.payable_amount', 'like', "%{$search}%")
                ->orWhere('subscriptions.subscription_amount_per_month', 'like', "%{$search}%")
                ->orWhere('subscriptions.monthly_platform_fee', 'like', "%{$search}%")
                ->orWhere('subscriptions.per_seat_amount', 'like', "%{$search}%")
                ->orWhereRaw("DATE_FORMAT(subscriptions.subscription_end_date, '%Y-%m-%d') LIKE ?", ["%{$search}%"])
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
     * Whether billing is currently stopped for this subscription.
     *
     * Checks the subscriber's LIVE status as well as billing_paused_at, because
     * that flag is only stamped when the cron next processes the row — so between
     * an admin suspending someone and the next cron run the flag is still null
     * while billing is, in reality, already stopped. Reading the status directly
     * keeps the screen honest in that window.
     *
     * Falls back to the flag alone when the relations are not loaded, so it is safe
     * under Model::preventLazyLoading().
     */
    public function getIsBillingPausedAttribute(): bool
    {
        if ($this->billing_paused_at) {
            return true;
        }

        return $this->billingPausedReason() !== null;
    }

    /**
     * Why billing is stopped, or null. Only inspects relations already loaded.
     */
    public function billingPausedReason(): ?string
    {
        if ($this->entity_type === Partner::class && $this->relationLoaded('partner')) {
            $partner = $this->partner;

            return ($partner && $partner->status !== Partner::STATUS_ACTIVE)
                ? 'Partner is ' . $partner->status
                : null;
        }

        if ($this->entity_type === Borrower::class && $this->relationLoaded('borrower')) {
            $borrower = $this->borrower;

            if (!$borrower) {
                return null;
            }

            if ($borrower->status !== Borrower::STATUS_ACTIVE) {
                return 'Borrower is ' . $borrower->status;
            }

            // A borrower reaches the platform through their partner, so a suspended
            // partner stops the borrower's billing too.
            if ($borrower->relationLoaded('partner') && $borrower->partner
                && $borrower->partner->status !== Partner::STATUS_ACTIVE) {
                return 'Partner ' . ($borrower->partner->company_name ?: $borrower->partner->full_name)
                    . ' is ' . $borrower->partner->status;
            }
        }

        return $this->billing_paused_at ? 'Billing paused' : null;
    }

    /**
     * The next amount due and when, given the subscription's current phase.
     *
     * Single source of truth for "what's the next bill" - used by both the
     * Subscription Details page (SubscriptionDetailsResource) and My Subscription
     * (MySubscriptionService), which previously computed this two different ways
     * and disagreed: MySubscriptionService fell back to payable_amount whenever
     * subscription_amount_per_month was empty, which is correct only during
     * enrollment - once enrollment_completed_at is set, a partner owes the
     * recurring monthly_platform_fee, not the (much larger) one-time enrollment
     * total again.
     *
     * @return array{date: mixed, type: ?string, amount: float|null}
     */
    public function nextBilling(): array
    {
        $isPartner = $this->entity_type === Partner::class;
        $isBorrower = $this->entity_type === Borrower::class;
        $isEnrollmentCompleted = !empty($this->enrollment_completed_at);
        $isInstallment = (int) $this->installment_type === 1;

        $date = null;
        $type = null;
        $amount = null;

        if ($isPartner) {
            if (!$isInstallment) {
                if ($isEnrollmentCompleted) {
                    // Enrollment paid off -> recurring platform fee.
                    $date = $this->subscription_end_date;
                    $type = 'Platform Fee';
                    $amount = $this->monthly_platform_fee;
                } else {
                    $date = $this->subscription_end_date ?? $this->first_payment_date;
                    $type = 'One Time Enrollment';
                    $amount = $this->payable_amount ?? $this->total_subscription_amount;
                }
            } else {
                $date = $this->subscription_end_date;
                if (!$isEnrollmentCompleted) {
                    if ((int) $this->installments_paid === 0 && (float) $this->down_payment > 0) {
                        $type = 'Down Payment';
                        $amount = $this->down_payment;
                    } else {
                        $type = 'Installment';
                        $amount = $this->subscription_amount_per_month ?? $this->payable_amount;
                    }
                } else {
                    $type = 'Platform Fee';
                    $amount = $this->monthly_platform_fee;
                }
            }
        } elseif ($isBorrower) {
            // A borrower owes nothing further once enrollment_completed_at is set -
            // there is no borrower platform fee.
            if (!$isEnrollmentCompleted) {
                $date = $this->subscription_end_date;
                $type = 'Installment';
                $amount = $this->subscription_amount_per_month ?? $this->payable_amount;
            }
        } else {
            // Additional Seat Subscription.
            $seatTotal = ($this->user_seats ?? 0) * ($this->per_seat_amount ?? 0);
            $date = $this->subscription_end_date;
            $type = 'Seat Fee';
            $amount = $seatTotal > 0 ? $seatTotal : $this->payable_amount;
        }

        return [
            'date' => $date,
            'type' => $type,
            'amount' => $amount !== null ? (float) $amount : null,
        ];
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
            Employee::class => User::EMPLOYEE,
            User::class => User::USER,
        ];

        return $map[$this->entity_type] ?? class_basename($this->entity_type);
    }
}
