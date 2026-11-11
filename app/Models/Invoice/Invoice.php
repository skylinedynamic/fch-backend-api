<?php

namespace App\Models\Invoice;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoices';

    protected $fillable = [
        'invoice_id',
        'partner_id',
        'borrower_id',
        'subscription_id',
        'entity_type', // Client, Borrower, etc.
        'entity_id',
        'customer_name',
        'email',
        'country',
        'payment_amount',
        'month_count',
        'payment_date',
        'auto_charge_stopped_at',
        'auto_charge_stop_reason',
        'status',
        'type',
        'is_partner_paying',
        'currency',
        'currency_symbol',
        'conversion_rate',
        'is_platform_fee',
        'details',
        'note',
        'created_by',
        'updated_by',
        // Who paid it and who approved its manual payment (BUG-202).
        'paid_by',
        'approved_by',
    ];


    /** Why the scheduler stopped auto-charging an invoice (auto_charge_stop_reason, BUG-005). */
    const AUTO_CHARGE_STOP_ALREADY_PAID = 'already_paid';
    const AUTO_CHARGE_STOP_TOO_MANY_FAILURES = 'too_many_failures';

    const STATUS_PAID = 'Paid';
    const STATUS_UNPAID = 'Unpaid';
    const STATUS_DISPUTED = 'Disputed';
    const STATUS_FAILED = 'Failed';
    const STATUS_PENDING = 'Pending';
    const STATUS_SUCCESS = 'Success';
    const STATUS_CANCELED = 'Canceled';
    const STATUS_OVERDUE = 'Overdue';
    // Not set any more (the roll-forward billing that wrote it was retired on
    // 2026-10-11); older invoices still carry it.
    const STATUS_EXPIRED = 'Expired';
    const STATUS_PROCESSING = 'Processing';
    const STATUS_ALL = 'All Status';

    public const CURRENCY_USD = 'USD';


    const PAYEE_ALL = 'All Payees';



    // Invoice types mirror Subscription::PHASE_* constants for full sync
    const INVOICE_TYPE_ENROLLMENT = 'enrollment';
    const INVOICE_TYPE_DOWN_PAYMENT = 'down_payment';
    const INVOICE_TYPE_INSTALLMENT = 'installment';
    const INVOICE_TYPE_PLATFORM_FEE = 'platform_fee';
    const INVOICE_TYPE_BORROWER_FEE = 'borrower_fee';

    /**
     * Shows Pay Now to a partner user for the partner's own invoices (BUG-202).
     * Checked by the panel only; the API lets the partner's staff pay either
     * way. Seeded by PayInvoicePermissionSeeder.
     */
    const PERMISSION_PAY = 'Pay Invoice';
    const INVOICE_TYPE_USER_SEAT = 'user_seat';
    const INVOICE_TYPE_COMMISSION_PAYOUT = 'commission_payout';

    /** Every invoice type, in the order the Invoices Type filter offers them. */
    const INVOICE_TYPES = [
        self::INVOICE_TYPE_ENROLLMENT,
        self::INVOICE_TYPE_DOWN_PAYMENT,
        self::INVOICE_TYPE_INSTALLMENT,
        self::INVOICE_TYPE_PLATFORM_FEE,
        self::INVOICE_TYPE_BORROWER_FEE,
        self::INVOICE_TYPE_USER_SEAT,
        self::INVOICE_TYPE_COMMISSION_PAYOUT,
    ];

    /**
     * What a partner pays the host: its own plan, its staff seats and the
     * borrower fee for each client it activates. A commission payout is money
     * going the other way.
     */
    const PARTNER_BILL_TYPES = [
        self::INVOICE_TYPE_ENROLLMENT,
        self::INVOICE_TYPE_DOWN_PAYMENT,
        self::INVOICE_TYPE_INSTALLMENT,
        self::INVOICE_TYPE_PLATFORM_FEE,
        self::INVOICE_TYPE_USER_SEAT,
        self::INVOICE_TYPE_BORROWER_FEE,
    ];

    /**
     * Still owed: never paid, or paid by a manual method whose approval is
     * pending (a Processing payment has not reached the receiver yet).
     */
    const OPEN_STATUSES = [self::STATUS_UNPAID, self::STATUS_PROCESSING];

    /**
     * The invoice types that ARE this subscription's own billing cycle.
     *
     * Billing raises no new invoice while one of these is unpaid, so the list
     * decides whose debt can hold a subscription's billing back. borrower_fee and
     * commission_payout are deliberately absent: a borrower fee is owed by the
     * PARTNER to the host and is only attached to the borrower's subscription for
     * traceability, and a payout is money going the other way. Neither is the
     * subscriber's own debt, so neither may stop them being billed.
     *
     * A NULL type counts as a cycle invoice. Legacy rows predate this column and
     * are overwhelmingly enrollment or installment charges, so the safe reading of
     * an untyped debt is "they owe us for this subscription".
     */
    const CYCLE_INVOICE_TYPES = [
        self::INVOICE_TYPE_ENROLLMENT,
        self::INVOICE_TYPE_DOWN_PAYMENT,
        self::INVOICE_TYPE_INSTALLMENT,
        self::INVOICE_TYPE_PLATFORM_FEE,
        self::INVOICE_TYPE_USER_SEAT,
    ];

    /**
     * The invoice types that bill for the subscription's plan itself.
     *
     * Only these may describe themselves with the subscription's plan (its
     * enrollment type). A borrower fee and a platform fee also hang off a
     * subscription, but they charge something else, so the plan is not theirs.
     */
    const PLAN_INVOICE_TYPES = [
        self::INVOICE_TYPE_ENROLLMENT,
        self::INVOICE_TYPE_DOWN_PAYMENT,
        self::INVOICE_TYPE_INSTALLMENT,
    ];

    /**
     * Names shown for a type where the stored value is not the name: the
     * partner's fee per client is stored as borrower_fee but named "Client
     * Activation Fee", as on Plan & Fee (BUG-194). Any other type is shown as
     * its stored value in words.
     */
    const INVOICE_TYPE_LABELS = [
        self::INVOICE_TYPE_BORROWER_FEE => 'Client Activation Fee',
    ];

    /**
     * The name an invoice type is shown by ("borrower_fee" -> "Client Activation Fee",
     * "user_seat" -> "User Seat"); empty for an untyped (older) invoice.
     */
    public static function typeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        return self::INVOICE_TYPE_LABELS[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * The stored types whose shown name contains $text, ignoring case, so a
     * filter typed as the table reads ("Client Activation Fee") runs on the
     * stored value (borrower_fee). Empty when no type's name matches.
     *
     * @return list<string>
     */
    public static function typesMatchingLabel(string $text): array
    {
        $needle = mb_strtolower(trim($text));
        if ($needle === '') {
            return [];
        }

        return array_values(array_filter(
            self::INVOICE_TYPES,
            fn (string $type) => str_contains(mb_strtolower(self::typeLabel($type)), $needle)
        ));
    }

    /**
     * The name this invoice's type is shown by.
     */
    public function typeName(): string
    {
        return self::typeLabel($this->type);
    }

    /**
     * Whether this invoice bills for its subscription's plan.
     */
    public function billsSubscriptionPlan(): bool
    {
        return in_array($this->type, self::PLAN_INVOICE_TYPES, true);
    }

    /**
     * An unsettled invoice that is the subscription's own billing cycle: Unpaid, or
     * Processing - a manual payment awaiting approval has not settled it either,
     * and raising the cycle again in the meantime billed (and auto-charged) a
     * month already paid in cash.
     *
     * The single definition of "this subscriber still owes us for their plan", used
     * both to decide whether to raise the next invoice and to exclude such rows in
     * bulk. Written once so the two can never drift apart.
     */
    public function scopeOpenCycle($query)
    {
        return $query->whereIn('status', [self::STATUS_UNPAID, self::STATUS_PROCESSING])
            ->where(function ($q) {
                $q->whereIn('type', self::CYCLE_INVOICE_TYPES)->orWhereNull('type');
            });
    }

    /**
     * Leave out commission payouts.
     *
     * A payout invoice carries the deal's borrower_id for traceability, but it is
     * money paid to a partner or staff user - never the borrower's own bill.
     * Untyped legacy rows are kept (a bare `type !=` would drop NULLs too).
     */
    public function scopeExcludingCommissionPayouts($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('invoices.type')
                ->orWhere('invoices.type', '!=', self::INVOICE_TYPE_COMMISSION_PAYOUT);
        });
    }



    protected $casts = [
        'details' => 'array',
        'payment_date' => 'date',
        'auto_charge_stopped_at' => 'datetime',
    ];

    /**
     * Whether succeeded payments already add up to this invoice's amount - it
     * was paid, whatever its status says (BUG-005: older code recorded a
     * Stripe payment and left the invoice Unpaid).
     */
    public function isCoveredBySucceededPayments(): bool
    {
        $paid = (float) Payment::where('invoice_id', $this->id)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->sum('amount');

        return $paid > 0 && round($paid, 2) >= round((float) $this->payment_amount, 2);
    }

    /**
     * What the invoice's line item, email and bell say when this is the last
     * installment of a plan and its amount differs from the monthly one
     * (BUG-049). The billing cron records the figures in
     * details.final_installment; null on every other invoice.
     *
     * @return array{number: int, count: int, adjustment: string, difference: string, monthly: string, total: string}|null
     */
    public function finalInstallmentNote(): ?array
    {
        $final = $this->details['final_installment'] ?? null;

        if (!is_array($final) || !isset($final['adjustment'], $final['monthly'], $final['total'], $final['count'])) {
            return null;
        }

        $adjustment = (float) $final['adjustment'];

        return [
            'number' => (int) $final['count'],
            'count' => (int) $final['count'],
            // Signed both ways: "-$0.02" or "+$0.01".
            'adjustment' => ($adjustment > 0 ? '+' : '') . currencyFormat($adjustment),
            // In words for the customer: "$0.02 less" or "$0.01 more".
            'difference' => __($adjustment < 0 ? 'invoice.final_installment.less' : 'invoice.final_installment.more', [
                'amount' => currencyFormat(abs($adjustment)),
            ]),
            'monthly' => currencyFormat($final['monthly']),
            'total' => currencyFormat($final['total']),
        ];
    }

    public function borrower(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function partner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * Whether paying this invoice makes a cancelled subscription active again
     * (BUG-069): an open cycle invoice of a cancelled subscription. The portals
     * warn before taking the payment.
     */
    public function reactivatesSubscription(): bool
    {
        $open = in_array($this->status, [self::STATUS_UNPAID, self::STATUS_PROCESSING], true)
            && ($this->type === null || in_array($this->type, self::CYCLE_INVOICE_TYPES, true));

        return $open
            && $this->subscription_id
            && $this->subscription?->status === Subscription::STATUS_CANCELLED;
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    /**
     * Scope: apply text search across common invoice fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('invoices.invoice_id', 'like', "%{$search}%")
                ->orWhere('invoices.customer_name', 'like', "%{$search}%")
                ->orWhere('invoices.email', 'like', "%{$search}%")
                ->orWhere('invoices.payment_amount', 'like', "%{$search}%")
                ->orWhere('invoices.payment_date', 'like', "%{$search}%")
                ->orWhere('invoices.created_at', 'like', "%{$search}%");

            // Accurate Status Matching: Use exact match if search matches a status to avoid "Paid" matching "Unpaid"
            $statuses = [
                Invoice::STATUS_PAID,
                Invoice::STATUS_UNPAID,
                Invoice::STATUS_DISPUTED,
                Invoice::STATUS_FAILED,
                Invoice::STATUS_PENDING,
                Invoice::STATUS_SUCCESS,
                Invoice::STATUS_CANCELED,
                Invoice::STATUS_OVERDUE,
            ];

            if (in_array(ucfirst(strtolower($search)), $statuses)) {
                $q->orWhere('invoices.status', ucfirst(strtolower($search)));
            } else {
                $q->orWhere('invoices.status', 'like', "%{$search}%");
            }

            $q->orWhereHas('partner', function ($pq) use ($search) {
                $pq->where('company_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            })->orWhereHas('borrower', function ($bq) use ($search) {
                $bq->where('business_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
            });
        });
    }



    /**
     * Accessor: human-readable entity name (partner/borrower/employee).
     */
    public function getEntityNameAttribute(): ?string
    {
        $map = [
            Partner::class => User::PARTNER,
            Borrower::class => User::BORROWER,
            Employee::class => User::EMPLOYEE,
        ];

        return $map[$this->entity_type] ?? strtolower(class_basename($this->entity_type));
    }

    /** Payment statuses that mean the invoice was paid this way (or is being, awaiting approval). */
    const PAYING_PAYMENT_STATUSES = [
        Payment::STATUS_SUCCEEDED,
        Payment::STATUS_PROCESSING,
    ];

    /**
     * How this invoice was actually paid, from the payment that pays it (the
     * latest succeeded one, or one awaiting approval): its method, the payer's
     * reference for a manual payment, and whether it was a card. All empty when
     * nothing has paid it - a failed or rejected attempt does not count (BUG-206).
     *
     * @return array{method: ?string, reference: ?string, is_card: bool}
     */
    public function paidWith(): array
    {
        $payment = $this->exists
            ? $this->payments()
                ->whereIn('status', self::PAYING_PAYMENT_STATUSES)
                ->latest('id')
                ->first()
            : null;

        if (!$payment) {
            return ['method' => null, 'reference' => null, 'is_card' => false];
        }

        $details = is_array($payment->details) ? $payment->details : [];
        $reference = trim((string) ($details['transaction_number'] ?? ''));

        return [
            'method' => $payment->payment_from ?: null,
            'reference' => $reference !== '' ? $reference : null,
            'is_card' => strcasecmp((string) $payment->payment_type, PaymentProvider::TYPE_CARD) === 0,
        ];
    }

    /**
     * Relationship: most recent payment associated with this invoice.
     */
    public function latestPayment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Payment::class, 'invoice_id')->latestOfMany();
    }

    /**
     * Check if the authenticated user can approve the payment for this invoice.
     */
    public function canApproveForUser(?User $authUser = null): bool
    {
        $payment = $this->relationLoaded('latestPayment')
            ? $this->latestPayment
            : ($this->relationLoaded('payments') ? $this->payments->first() : $this->payments()->latest('id')->first());

        return $payment ? $payment->canApproveForUser($authUser) : false;
    }

    /**
     * Alias helper for canApproveForUser.
     */
    public function canApprove(?User $authUser = null): bool
    {
        return $this->canApproveForUser($authUser ?? auth()->user());
    }
}
