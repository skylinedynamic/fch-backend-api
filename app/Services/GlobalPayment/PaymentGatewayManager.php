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
     * Resolve and initialize a payment driver for a partner and provider.
     *
     * @param int|null $partnerId
     * @param string|null $providerName Optional provider name (defaults to active default)
     * @return PaymentGatewayInterface
     */
    public function resolveForPartner(?int $partnerId, ?string $providerName = null): PaymentGatewayInterface
    {
        $query = PartnerPaymentProvider::query()
            ->with('provider')
            ->where('status', true);

        if ($partnerId) {
            $query->where('partner_id', $partnerId);
        } else {
            $host = Partner::where('is_host', true)->first();
            if ($host) {
                $query->where('partner_id', $host->id);
            }
        }

        if ($providerName) {
            $query->whereHas('provider', function ($q) use ($providerName) {
                $q->where('name', $providerName);
            });
        } else {
            $query->where('is_default', true);
        }

        $partnerProvider = $query->first();

        // Fallback to first active provider if default is not explicitly marked
        if (!$partnerProvider && !$providerName) {
            $partnerProvider = PartnerPaymentProvider::query()
                ->with('provider')
                ->where('status', true)
                ->where(function ($q) use ($partnerId) {
                    if ($partnerId) {
                        $q->where('partner_id', $partnerId);
                    }
                })
                ->first();
        }

        if (!$partnerProvider) {
            throw new RuntimeException("No active payment provider configured for partner ID: " . ($partnerId ?? 'Host'));
        }

        $providerName = $partnerProvider->provider?->name ?? PaymentProvider::STRIPE;
        $configuration = $partnerProvider->configuration ?? [];
        $environment = $configuration['payment_mode'] ?? 'sandbox';

        return $this->driver($providerName)->initialize($configuration, $environment);
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
