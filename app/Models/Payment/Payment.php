<?php

namespace App\Models\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stripe\Stripe;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payments';

    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'datetime',
        'details' => 'array',
        'is_environment' => 'boolean',
        'is_manual' => 'boolean',
        'is_partner_paying' => 'boolean',
    ];

    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_REQUIRES_CAPTURE = 'requires_capture';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PROCESSING = 'processing';

    public const TYPE_PAYOUT = 'payout';

    /**
     * Where a FAILED row came from, kept in its details. A declined "Pay Now"
     * attempt is marked so the auto-charge cron does not count it among its own
     * failed attempts (BUG-035).
     */
    public const DETAIL_FAILURE_SOURCE = 'source';
    public const FAILURE_SOURCE_CHECKOUT = 'checkout';

    /**
     * A "Pay Now" card charge the issuer wants authenticated - 3-D Secure,
     * usually an OTP (BUG-066). It is kept as a PENDING row, marked in details,
     * while the payer completes the bank's challenge in the browser. The
     * confirm endpoint, or the payments:settle-card-authentications command for
     * a payer who closed the tab, then settles it as SUCCEEDED or FAILED.
     *
     * The gateway account is kept with it, so the PaymentIntent is read back from
     * the same Stripe account - and mode - that created it.
     */
    public const DETAIL_AWAITING = 'awaiting';
    public const AWAITING_AUTHENTICATION = 'authentication';
    public const DETAIL_GATEWAY_ACCOUNT = 'partner_payment_provider_id';

    /** How long a payer has to finish the bank's challenge before it is cancelled. */
    public const AUTHENTICATION_TIMEOUT_MINUTES = 30;

    public const TRANSACTION_TYPE_DEBIT = 'Debit';
    public const TRANSACTION_TYPE_CREDIT = 'Credit';

    public const CURRENCY_USD = 'USD';
    public const CURRENCY_SYMBOL_USD = '$';

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saved(function (Payment $payment) {
            if ($payment->status === self::STATUS_SUCCEEDED && ($payment->wasRecentlyCreated || $payment->wasChanged('status'))) {
                $entityType = $payment->entity_type ?: $payment->invoice?->entity_type;
                $entityId = $payment->entity_id ?: $payment->invoice?->entity_id;



                if ($entityId) {
                    if (!$entityType || $entityType === User::class) {
                        User::where('id', $entityId)
                            ->first()
                                ?->markAsRefresh();
                    } else {
                        User::where('entity_type', $entityType)
                            ->where('entity_id', $entityId)
                            ->first()
                                ?->markAsRefresh();
                    }
                }
            }
        });
    }

    /**
     * Determine transaction type (Debit vs Credit) for a specific user role.
     */
    public function getTransactionTypeForUser(?User $authUser = null): string
    {
        // A commission payout is money going out to the partner or user it was
        // paid to — a Credit for them, a Debit for whoever paid it (the admin) —
        // whichever kind of account is looking.
        if ($this->isCommissionPayout()) {
            return $this->isReceivedBy($authUser) ? self::TRANSACTION_TYPE_CREDIT : self::TRANSACTION_TYPE_DEBIT;
        }

        $isPartnerPaying = (bool) $this->is_partner_paying || $this->entity_type === Partner::class;
        $status = strtolower((string) ($this->status ?? ''));
        $paymentType = strtolower((string) ($this->payment_type ?? ''));

        if ($status === self::STATUS_REFUNDED || $paymentType === self::TYPE_PAYOUT) {
            if ($authUser && $authUser->entity_type === Borrower::class) {
                return self::TRANSACTION_TYPE_CREDIT;
            }
            return self::TRANSACTION_TYPE_DEBIT;
        }

        if ($authUser && $authUser->entity_type === Borrower::class) {
            return self::TRANSACTION_TYPE_DEBIT;
        }

        if (!empty($authUser?->partner) && (int) ($authUser->partner->is_host ?? 0) === 0) {
            return $isPartnerPaying ? self::TRANSACTION_TYPE_DEBIT : self::TRANSACTION_TYPE_CREDIT;
        }

        return self::TRANSACTION_TYPE_CREDIT;
    }

    /** Whether this is a payout of a commission (to a partner, or to a host-partner client's assigned user). */
    public function isCommissionPayout(): bool
    {
        return $this->invoice?->type === Invoice::INVOICE_TYPE_COMMISSION_PAYOUT;
    }

    /** Whether $user is who this payment was made to: the paid user, or any user of the paid partner. */
    public function isReceivedBy(?User $user): bool
    {
        if (!$user || !$this->entity_id) {
            return false;
        }

        return match ($this->entity_type) {
            Partner::class => (int) $user->partner_id === (int) $this->entity_id,
            User::class => (int) $user->id === (int) $this->entity_id,
            default => false,
        };
    }

    /**
     * Determine if an authenticated user can approve this payment.
     */
    public function canApproveForUser(?User $authUser = null): bool
    {
        if (!$authUser || $authUser->entity_type === Borrower::class) {
            return false;
        }

        if (strtolower((string) ($this->status ?? '')) !== self::STATUS_PROCESSING) {
            return false;
        }

        $authUserPartner = $authUser->partner;
        $isHost = (bool) ($authUserPartner?->is_host ?? (bool) ($authUser->is_host ?? false));
        $authUserPartnerId = $authUser->partner_id;

        // Host partner: can approve partner payments to host (borrower fee invoices are partner-paid too),
        // or host's own borrower payments. A white-label partner's borrower pays that partner, not the
        // host, so the host has nothing to confirm there.
        if ($isHost) {
            return (bool) $this->is_partner_paying
                || $this->entity_type === Partner::class
                || (int) $this->partner_id === (int) $authUserPartnerId;
        }

        // Non-host partner: only what its own borrowers paid it. What the partner
        // pays the host - the borrower fee included, though it names a borrower -
        // is the host's to confirm; a partner confirming it would mark the host
        // paid with nothing received.
        return (int) $this->partner_id === (int) $authUserPartnerId
            && $this->entity_type === Borrower::class
            && !$this->is_partner_paying;
    }

    /**
     * Alias helper for canApproveForUser.
     */
    public function canApprove(?User $authUser = null): bool
    {
        return $this->canApproveForUser($authUser ?? auth()->user());
    }

    /**
     * Create a payment record dynamically.
     */
    /**
     * A card payment waiting on the payer's bank authentication (see
     * DETAIL_AWAITING).
     */
    public function isAwaitingAuthentication(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->details[self::DETAIL_AWAITING] ?? null) === self::AWAITING_AUTHENTICATION;
    }

    /**
     * Scope: card payments waiting on the payer's bank authentication.
     */
    public function scopeAwaitingAuthentication($query)
    {
        return $query->where('payments.status', self::STATUS_PENDING)
            ->where('payments.details->' . self::DETAIL_AWAITING, self::AWAITING_AUTHENTICATION);
    }

    public function createRecord(array $data): self
    {
        // Never guessed: the caller knows which payment method the money was
        // taken with (or the attempt made with), and that method's mode is the
        // environment - see environmentOf(). Guessing from the partner's Stripe
        // filed Production cash payments under Sandbox.
        if (!isset($data['is_environment'])) {
            throw new \InvalidArgumentException('A payment must say which environment (sandbox / production) it was made in.');
        }

        $partnerId = $data['partner_id'] ?? null;
        $isEnvironment = $data['is_environment'];
        $paymentFrom = $data['payment_from'] ?? PaymentProvider::STRIPE;
        $paymentType = $data['payment_type'] ?? ($paymentFrom === PaymentProvider::STRIPE ? PaymentProvider::TYPE_CARD : $paymentFrom);

        return self::create([
            'partner_id' => $partnerId,
            'borrower_id' => $data['borrower_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'amount' => $data['amount'] ?? 0.00,
            'currency' => $data['currency'] ?? self::CURRENCY_USD,
            'payment_from' => $paymentFrom,
            'payment_type' => $paymentType,
            'status' => $data['status'] ?? self::STATUS_PENDING,
            'is_environment' => $isEnvironment,
            'is_manual' => (bool) ($data['is_manual'] ?? false),
            'details' => $data['details'] ?? null,
            'transaction_date' => $data['transaction_date'] ?? now(),
            'created_by' => $data['created_by'] ?? auth()->id(),
        ]);
    }

    /**
     * Configure Stripe with the appropriate secret key for a partner.
     */
    public function configureStripeForPartner(?int $partnerId): object
    {
        $stripeProvider = PaymentProvider::where('name', PaymentProvider::STRIPE)->first();

        if (!$stripeProvider) {
            throw new \Exception(__('payment.stripe_provider_not_found'));
        }

        $linkage = PartnerPaymentProvider::where('partner_id', $partnerId)
            ->where('payment_provider_id', $stripeProvider->id)
            ->first();

        if (!$linkage) {
            throw new \Exception(__('payment.stripe_not_configured'));
        }

        $paymentMode = $linkage->paymentMode();
        $secretKey = $linkage->secretKey();

        if (!$secretKey) {
            throw new \Exception(__('payment.stripe_secret_not_configured', ['mode' => $paymentMode]));
        }

        Stripe::setApiKey($secretKey);

        // Return a compatible settings object for callers that use $setting->payment_mode, etc.
        $setting = new \stdClass();
        $setting->partner_id = $partnerId;
        $setting->payment_mode = $paymentMode;

        return $setting;
    }

    /**
     * The environment of a payment taken with (or attempted on) this payment
     * method: the mode the receiver has it set to. No method set up means no
     * live money could have moved, so Sandbox.
     */
    public static function environmentOf(?PartnerPaymentProvider $method): int
    {
        return self::environmentFromMode($method?->paymentMode() ?? PaymentProvider::MODE_SANDBOX);
    }

    public static function environmentFromMode(string $mode): int
    {
        return $mode === PaymentProvider::MODE_PRODUCTION
            ? PaymentProvider::ENVIRONMENT_PRODUCTION
            : PaymentProvider::ENVIRONMENT_SANDBOX;
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id', 'id');
    }

    public function borrower()
    {
        return $this->belongsTo(Borrower::class, 'borrower_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice\Invoice::class, 'invoice_id');
    }

    /**
     * Polymorphic relation to the associated entity model.
     */
    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    /**
     * Scope: apply text search across common payment fields.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!is_string($search) || trim($search) === '') {
            return $query;
        }

        $search = trim($search);

        return $query->where(function ($q) use ($search) {
            $q->where('payments.transaction_id', 'like', "%{$search}%")
                ->orWhere('payments.payment_from', 'like', "%{$search}%")
                ->orWhere('payments.payment_type', 'like', "%{$search}%")
                ->orWhere('payments.amount', 'like', "%{$search}%")
                ->orWhere('payments.transaction_date', 'like', "%{$search}%");

            // Accurate Status Matching: Ensure exact match for the model's status constants
            $statuses = [
                self::STATUS_SUCCEEDED,
                self::STATUS_FAILED,
                self::STATUS_REJECTED,
                self::STATUS_PENDING,
                self::STATUS_DISPUTED,
                self::STATUS_REFUNDED,
            ];
            $searchStatus = strtolower(trim($search));

            if (in_array($searchStatus, $statuses)) {
                $q->orWhere('payments.status', $searchStatus);
            } else {
                $q->orWhere('payments.status', 'like', "%{$search}%");
            }

            $q->orWhereHas('partner', function ($pq) use ($search) {
                $pq->where('company_name', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%");
            })
                ->orWhereHas('borrower', function ($bq) use ($search) {
                    $bq->where('business_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) LIKE ?", ["%{$search}%"]);
                })
                ->orWhereHas('invoice', function ($iq) use ($search) {
                    $iq->where('invoice_id', 'like', "%{$search}%");
                });

            // Environment Search Accuracy: Map "Sandbox" and "Production" to boolean values
            if (str_contains(strtolower($search), 'sand')) {
                $q->orWhere('payments.is_environment', PaymentProvider::ENVIRONMENT_SANDBOX);
            } elseif (str_contains(strtolower($search), 'prod')) {
                $q->orWhere('payments.is_environment', PaymentProvider::ENVIRONMENT_PRODUCTION);
            }
        });
    }
}
