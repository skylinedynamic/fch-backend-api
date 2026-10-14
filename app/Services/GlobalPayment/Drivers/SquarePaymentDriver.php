<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use RuntimeException;

class SquarePaymentDriver implements PaymentGatewayInterface
{
    protected array $config = [];
    protected string $environment = 'sandbox';

    public function initialize(array $config, string $environment = 'sandbox'): self
    {
        $this->config = $config;
        $this->environment = $environment;
        return $this;
    }

    /**
     * The Square integration is not built. Every call fails closed: a driver
     * that reported success here would mark invoices paid, or customers and
     * subscriptions created, with nothing happening at Square.
     */
    public function charge(array $params): array
    {
        return $this->notImplemented();
    }

    public function createCustomer(array $params): array
    {
        return $this->notImplemented();
    }

    public function createSubscription(array $params): array
    {
        return $this->notImplemented();
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return false;
    }

    private function notImplemented(): array
    {
        return [
            'success' => false,
            'error' => 'Square payments are not available yet.',
        ];
    }
}
