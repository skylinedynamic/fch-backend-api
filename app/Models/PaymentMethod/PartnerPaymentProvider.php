<?php

namespace App\Models\PaymentMethod;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Partner\Partner;
use App\Support\Payment\PaymentCredentials;

class PartnerPaymentProvider extends Model
{
    use HasFactory;

    protected $table = 'partner_payment_providers';

    protected $fillable = [
        'partner_id',
        'payment_provider_id',
        'status',
        'is_default',
        'payment_mode',
        'configuration',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_default' => 'boolean',
        'configuration' => 'encrypted:array',
    ];

    /**
     * The mode this linkage transacts in: `sandbox` or `production`.
     *
     * Always prefer this over reading `payment_mode` or the configuration
     * directly - see PaymentCredentials for why the two can disagree.
     */
    public function paymentMode(): string
    {
        return PaymentCredentials::mode($this->payment_mode, $this->configuration);
    }

    /**
     * The stored configuration as an array, whatever shape it came back in.
     */
    public function configurationArray(): array
    {
        return PaymentCredentials::toArray($this->configuration);
    }

    /**
     * The credential block for the active mode - provider-specific keys included
     * (Square's `app_id`, Authorize.Net's `login_id`, and so on).
     */
    public function credentials(?string $mode = null): array
    {
        return PaymentCredentials::forMode($this->configuration, $mode ?? $this->paymentMode());
    }

    /**
     * The gateway secret key for the active mode, or null when unconfigured.
     */
    public function secretKey(?string $mode = null): ?string
    {
        return PaymentCredentials::secretKey($this->configuration, $mode ?? $this->paymentMode());
    }

    /**
     * The publishable key for the active mode, or null when unconfigured.
     *
     * This is the key handed to the browser, and it must resolve from the same
     * mode the server will charge with.
     */
    public function publishableKey(?string $mode = null): ?string
    {
        return PaymentCredentials::publishableKey($this->configuration, $mode ?? $this->paymentMode());
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function provider()
    {
        return $this->belongsTo(PaymentProvider::class, 'payment_provider_id');
    }
}
