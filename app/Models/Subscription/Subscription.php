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
 * subscription_end_date is the one cycle date: what the customer is paid through
 * AND when the cron next bills them. It only moves on a real payment
 * (handleOneTimePayment / handleInstallmentPayment / handleUserSeatPayment in
 * SubscriptionService), and generateDueInvoices() / createInvoiceIfDue() key off it.
 *
 * NULL means nothing is scheduled: a borrower who has paid in full, or a
 * subscription whose first invoice has not been settled yet.
 *
 * There is no second scheduling date. Only one invoice may be open at a time, so a
 * cycle is never rolled forward without payment and the two can never diverge.
 * Both portals also lock a profile once today is past this date, which is why a
 * finished borrower must carry NULL rather than a date in the past.
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

    /**
     * How long a partner's One Time Payment covers before the monthly platform fee
     * starts, counted from the payment date.
     */
    const ONE_TIME_PAYMENT_COVERAGE_MONTHS = 12;

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
        // A calendar date: the day enrollment finished. Never a moment -
        // see the migration that narrowed the column.
        'enrollment_completed_at' => 'date',
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
     * Scope: order by the subscriber's name as the lists show it - a partner's
     * full name, a client's first / middle / last name, a seat's user or
     * employee name. The name is not a column, so each part is read with a
     * correlated subquery: no join, which would let the joined table's id and
     * status overwrite the subscription's own.
     */
    public function scopeOrderBySubscriberName($query, string $direction = 'asc')
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $part = fn (string $table, string $column) => "(SELECT {$table}.{$column} FROM {$table} WHERE {$table}.id = subscriptions.entity_id)";

        return $query
            ->orderByRaw(
                'CASE subscriptions.entity_type WHEN ? THEN ' . $part('partners', 'full_name')
                . ' WHEN ? THEN ' . $part('borrowers', 'first_name')
                . ' WHEN ? THEN ' . $part('users', 'name')
                . ' WHEN ? THEN ' . $part('employees', 'first_name')
                . " END {$direction}",
                [Partner::class, Borrower::class, User::class, Employee::class]
            )
            ->orderByRaw('CASE subscriptions.entity_type WHEN ? THEN ' . $part('borrowers', 'middle_name') . " END {$direction}", [Borrower::class])
            ->orderByRaw('CASE subscriptions.entity_type WHEN ? THEN ' . $part('borrowers', 'last_name') . " END {$direction}", [Borrower::class]);
    }

    /**
     * Whether billing is currently stopped for this subscription.
     *
     * Derived from the subscriber's live status every time it is asked, never from
     * a stored flag. There used to be a billing_paused_at column caching this, but
     * it was only stamped when the cron next processed the row — so between an
     * admin suspending someone and the next cron run it said "not paused" while
     * billing had in reality already stopped, and every reader had to check the
     * live status anyway to cover the gap.
     */
    public function getIsBillingPausedAttribute(): bool
    {
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

        // Seat subscriptions: the seat holder's own user account decides.
        if (in_array($this->entity_type, [User::class, Employee::class], true)
            && $this->relationLoaded('entity') && $this->entity) {
            $seatStatus = $this->entity_type === User::class
                ? $this->entity->status
                : $this->entity->job_status;

            if ($seatStatus !== null && $seatStatus !== User::STATUS['active']
                && $seatStatus !== Employee::STATUS_ACTIVE) {
                return class_basename($this->entity_type) . ' is ' . $seatStatus;
            }
        }

        return null;
    }

    /**
     * Constrain to subscriptions whose billing is stopped, in SQL.
     *
     * The query-side twin of billingPausedReason(). Shared by the billing screens
     * and by the auto-charge dispatcher so the two can never disagree about who is
     * collectable.
     */
    public function scopeBillingBlocked($query)
    {
        return $query->where(function ($outer) {
            $outer->where(function ($q) {
                $q->where('subscriptions.entity_type', Partner::class)
                    ->whereExists(function ($inner) {
                        $inner->selectRaw(1)
                            ->from('partners')
                            ->whereColumn('partners.id', 'subscriptions.entity_id')
                            ->where('partners.status', '!=', Partner::STATUS_ACTIVE);
                    });
            })->orWhere(function ($q) {
                // Borrower blocked by their own status, or by their partner's.
                $q->where('subscriptions.entity_type', Borrower::class)
                    ->whereExists(function ($inner) {
                        $inner->selectRaw(1)
                            ->from('borrowers')
                            ->whereColumn('borrowers.id', 'subscriptions.entity_id')
                            ->where(function ($w) {
                                $w->where('borrowers.status', '!=', Borrower::STATUS_ACTIVE)
                                    ->orWhereExists(function ($p) {
                                        $p->selectRaw(1)
                                            ->from('partners')
                                            ->whereColumn('partners.id', 'borrowers.partner_id')
                                            ->where('partners.status', '!=', Partner::STATUS_ACTIVE);
                                    });
                            });
                    });
            });
        });
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
