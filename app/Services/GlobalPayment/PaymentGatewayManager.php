<?php

namespace App\Services\GlobalPayment;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Drivers\StripePaymentDriver;
use App\Services\GlobalPayment\Drivers\EcryptPaymentDriver;
use App\Services\GlobalPayment\Drivers\SquarePaymentDriver;
use App\Services\GlobalPayment\Drivers\AuthorizeNetPaymentDriver;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class PaymentGatewayManager
{
    /**
     * Map of provider name to driver class.
     *
     * @var array<string, string>
     */
    protected array $drivers = [
        PaymentProvider::STRIPE => StripePaymentDriver::class,
        PaymentProvider::ECRYPT => EcryptPaymentDriver::class,
        PaymentProvider::SQUARE => SquarePaymentDriver::class,
        PaymentProvider::AUTHORIZE_NET => AuthorizeNetPaymentDriver::class,
    ];

    /**
     * Resolve the active partner-payment-provider configuration for a partner and provider ID.
     *
     * @param int|null $partnerId
     * @param int|null $providerId Optional payment_providers.id (defaults to the partner's default provider)
     * @return PartnerPaymentProvider
     */
    public function resolvePartnerProvider(?int $partnerId, ?int $providerId = null): PartnerPaymentProvider
    {
        $query = PartnerPaymentProvider::query()
            ->with('provider')
            ->where('partner_id', $partnerId)
            ->where('status', true);

        if ($providerId) {
            $query->where('payment_provider_id', $providerId);
        } else {
            $query->orderByDesc('is_default');
        }

        $partnerProvider = $query->first();

        if (!$partnerProvider) {
            throw new RuntimeException("No active payment provider configured for partner ID: " . ($partnerId ?? 'Host'));
        }

        return $partnerProvider;
    }

    /**
     * Initialize a payment driver from a resolved partner-payment-provider configuration.
     *
     * @param PartnerPaymentProvider $partnerProvider
     * @return PaymentGatewayInterface
     */
    public function driverFor(PartnerPaymentProvider $partnerProvider): PaymentGatewayInterface
    {
        $providerName = $partnerProvider->provider?->name ?? PaymentProvider::STRIPE;
        $configuration = $partnerProvider->configurationArray();
        $environment = $partnerProvider->paymentMode();

        Log::debug("[PaymentGatewayManager] Initializing payment driver", [
            'partner_id' => $partnerProvider->partner_id,
            'payment_provider_id' => $partnerProvider->payment_provider_id,
            'provider_name' => $providerName,
            'environment' => $environment,
        ]);

        return $this->driver($providerName)->initialize($configuration, $environment);
    }

    /**
     * Resolve and initialize a payment driver for a partner and provider.
     *
     * @param int|null $partnerId
     * @param int|null $providerId Optional payment_providers.id (defaults to active default)
     * @return PaymentGatewayInterface
     */
    public function resolveForPartner(?int $partnerId, ?int $providerId = null): PaymentGatewayInterface
    {
        return $this->driverFor($this->resolvePartnerProvider($partnerId, $providerId));
    }

    /**
     * Get an uninitialized instance of a gateway driver by name.
     *
     * @param string $providerName
     * @return PaymentGatewayInterface
     */
    public function driver(string $providerName): PaymentGatewayInterface
    {
        // Normalize name lookup (e.g. 'stripe' -> 'Stripe')
        $normalizedName = match (strtolower(trim($providerName))) {
            'stripe' => PaymentProvider::STRIPE,
            'ecrypt', 'e_crypt' => PaymentProvider::ECRYPT,
            'square' => PaymentProvider::SQUARE,
            'authorize_net', 'authorizenet', 'authorize.net' => PaymentProvider::AUTHORIZE_NET,
            default => $providerName,
        };

        if (!isset($this->drivers[$normalizedName])) {
            throw new InvalidArgumentException("Unsupported payment gateway driver: {$providerName}");
        }

        $driverClass = $this->drivers[$normalizedName];

        return new $driverClass();
    }
}
