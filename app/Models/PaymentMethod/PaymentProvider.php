<?php

namespace App\Models\PaymentMethod;

use App\Support\Payment\PaymentSetup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{
    use HasFactory;

    protected $table = 'payment_providers';

    protected $fillable = [
        'name',
        'display_name',
        'logo',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Prevent modifying 'name' once created.
     */
    protected static function boot()
    {
        parent::boot();

        static::updating(function ($provider) {
            if ($provider->isDirty('name')) {
                $provider->name = $provider->getOriginal('name');
            }
        });
    }

    public const STRIPE = 'Stripe';
    public const ECRYPT = 'Ecrypt';
    public const SQUARE = 'Square';
    public const AUTHORIZE_NET = 'Authorize.Net';
    public const CASH = 'Cash Payment';
    public const BANK_TRANSFER = 'Bank Transfer';

    public const TYPE_CARD = 'Card';

    public const ENVIRONMENT_SANDBOX = 0;
    public const ENVIRONMENT_PRODUCTION = 1;

    public const MODE_SANDBOX = 'sandbox';
    public const MODE_PRODUCTION = 'production';

    public const MODES = [self::MODE_SANDBOX, self::MODE_PRODUCTION];

    /**
     * The mode to suggest where a person states it by hand (a commission
     * payout): Production only on the production app, Sandbox everywhere else.
     */
    public static function defaultModeForApp(): string
    {
        return app()->isProduction() ? self::MODE_PRODUCTION : self::MODE_SANDBOX;
    }

    public function partnerPaymentProviders()
    {
        return $this->hasMany(PartnerPaymentProvider::class, 'payment_provider_id');
    }

    /**
     * Whether a gateway is on the platform, switched on by this partner and set
     * up to take payments - the same list GET /payment/get-payment-methods
     * offers, so a default payment method can only be one the payer was
     * actually offered. Stripe switched on without keys is not (BUG-151).
     */
    public static function isOfferedBy(?string $name, ?int $partnerId): bool
    {
        if (!$name || !$partnerId) {
            return false;
        }

        $provider = static::query()
            ->where('status', true)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();

        $linkage = $provider?->partnerPaymentProviders()
            ->where('partner_id', $partnerId)
            ->where('status', true)
            ->first();

        return PaymentSetup::isReady($linkage, $provider?->name);
    }
}
