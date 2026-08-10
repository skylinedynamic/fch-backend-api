<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\User\User;

use App\Models\PaymentMethod\PaymentMethod;

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
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PROCESSING = 'processing';

    public const TYPE_PAYOUT = 'payout';

    public const TRANSACTION_TYPE_DEBIT = 'Debit';
    public const TRANSACTION_TYPE_CREDIT = 'Credit';

    public const ENVIRONMENT_SANDBOX = 0;
    public const ENVIRONMENT_PRODUCTION = 1;

    public const MODE_SANDBOX = 'sandbox';
    public const MODE_PRODUCTION = 'production';

    public const PAYMENT_FROM_STRIPE = 'Stripe';
    public const PAYMENT_TYPE_CARD = 'Card';
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_SYMBOL_USD = '$';

    /**
     * Determine transaction type (Debit vs Credit) for a specific user role.
     */
    public function getTransactionTypeForUser(?User $authUser = null): string
    {
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

    /**
     * Determine if an authenticated user can approve this payment.
     */
    public function canApproveForUser(?User $authUser = null): bool
    {
        if (strtolower((string) ($this->status ?? '')) !== self::STATUS_PROCESSING) {
            return false;
        }

        if (empty($authUser)) {
            return false;
        }

        $isPartnerPaying = (bool) $this->is_partner_paying || $this->entity_type === Partner::class;
        $isHost = !empty($authUser->partner) ? (int) ($authUser->partner->is_host ?? 0) === 1 : ($authUser->hasRole('Super Admin') || $authUser->hasRole('Admin'));

        if ($isHost) {
            return true;
        }

        if (!empty($authUser->partner)) {
            return (int) $this->partner_id === (int) $authUser->partner_id && !$isPartnerPaying;
        }

        return false;
    }

    /**
     * Create a payment record dynamically.
     */
    public function createRecord(array $data): self
    {
        $partnerId = $data['partner_id'] ?? null;
        $isEnvironment = $data['is_environment'] ?? $this->getEnvironmentForPartner($partnerId);
        $paymentFrom = $data['payment_from'] ?? self::PAYMENT_FROM_STRIPE;
        $paymentType = $data['payment_type'] ?? ($paymentFrom === self::PAYMENT_FROM_STRIPE ? self::PAYMENT_TYPE_CARD : $paymentFrom);

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
        $paymentMethodService = new \App\Services\FCH\Common\PaymentMethod\PaymentMethodService();
        $setting = $paymentMethodService->getSettings($partnerId);

        if (!$setting) {
            throw new \Exception('Payment settings not found or inactive.');
        }

        $secretKey = ($setting->payment_mode === 'production')
            ? $setting->stripe_secret_production
            : $setting->stripe_secret_sandbox;

        if (!$secretKey) {
            throw new \Exception('Stripe secret key not configured for ' . $setting->payment_mode . ' mode.');
        }

        Stripe::setApiKey($secretKey);

        return $setting;
    }

    /**
     * Resolve environment based on Partner's payment mode.
     */
    public function getEnvironmentForPartner(?int $partnerId): int
    {
        if ($partnerId) {
            $partner = Partner::find($partnerId);
            if ($partner && $partner->partner_type !== Partner::PARTNER_TYPE_WHITE_LABEL && !$partner->is_host) {
                $host = Partner::where('is_host', 1)->first();
                if ($host) {
                    $partnerId = $host->id;
                }
            }
        }

        $paymentMode = PaymentMethod::where('partner_id', $partnerId)
            ->where('key', 'payment_mode')
            ->first()?->value ?? self::MODE_SANDBOX;

        return $this->environmentFromMode($paymentMode);
    }

    public function environmentFromMode(string $mode): int
    {
        return $mode === self::MODE_PRODUCTION
            ? self::ENVIRONMENT_PRODUCTION
            : self::ENVIRONMENT_SANDBOX;
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
                $q->orWhere('payments.is_environment', self::ENVIRONMENT_SANDBOX);
            } elseif (str_contains(strtolower($search), 'prod')) {
                $q->orWhere('payments.is_environment', self::ENVIRONMENT_PRODUCTION);
            }
        });
    }
}
