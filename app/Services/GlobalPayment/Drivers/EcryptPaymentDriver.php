<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use RuntimeException;

class EcryptPaymentDriver implements PaymentGatewayInterface
{
    protected array $config = [];
    protected string $environment = 'sandbox';

    public function initialize(array $config, string $environment = 'sandbox'): self
    {
        $this->config = $config;
        $this->environment = $environment;
        return $this;
    }

    public function charge(array $params): array
    {
        // E-Crypt charge driver implementation
        return [
            'success' => true,
            'transaction_id' => 'ECRYPT_TX_' . uniqid(),
            'raw' => $params,
        ];
    }

    public function createCustomer(array $params): array
    {
        return [
            'success' => true,
            'customer_id' => 'ECRYPT_CUST_' . uniqid(),
            'raw' => $params,
        ];
    }

    public function createSubscription(array $params): array
    {
        return [
            'success' => true,
            'subscription_id' => 'ECRYPT_SUB_' . uniqid(),
            'raw' => $params,
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return true;
    }
}
