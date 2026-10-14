<?php

namespace App\Models\PaymentMethod;

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

    public function partnerPaymentProviders()
    {
        return $this->hasMany(PartnerPaymentProvider::class, 'payment_provider_id');
    }

    /**
     * Whether a gateway is on the platform and switched on by this partner - the
     * same list GET /payment/providers offers, so a default payment method can
     * only be one the payer was actually offered.
     */
    public static function isOfferedBy(?string $name, ?int $partnerId): bool
    {
        if (!$name || !$partnerId) {
            return false;
        }

        return static::query()
            ->where('status', true)
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->whereHas('partnerPaymentProviders', fn ($q) => $q->where('partner_id', $partnerId)->where('status', true))
            ->exists();
    }
}
