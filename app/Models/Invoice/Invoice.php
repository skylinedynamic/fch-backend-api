<?php

namespace App\Models\Invoice;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
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
    ];


    const STATUS_PAID = 'Paid';
    const STATUS_UNPAID = 'Unpaid';
    const STATUS_DISPUTED = 'Disputed';
    const STATUS_FAILED = 'Failed';
    const STATUS_PENDING = 'Pending';
    const STATUS_SUCCESS = 'Success';
    const STATUS_CANCELED = 'Canceled';
    const STATUS_OVERDUE = 'Overdue';
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
    const INVOICE_TYPE_USER_SEAT = 'user_seat';
    const INVOICE_TYPE_COMMISSION_PAYOUT = 'commission_payout';

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



    protected $casts = [
        'details' => 'array',
        'payment_date' => 'date',
    ];

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
