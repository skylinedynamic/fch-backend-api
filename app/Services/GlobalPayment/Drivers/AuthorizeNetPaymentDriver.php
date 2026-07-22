<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use RuntimeException;

class AuthorizeNetPaymentDriver implements PaymentGatewayInterface
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
        // Authorize.Net charge driver implementation
        return [
            'success' => true,
            'transaction_id' => 'AUTHNET_TX_' . uniqid(),
            'raw' => $params,
        ];
    }

    public function createCustomer(array $params): array
    {
        return [
            'success' => true,
            'customer_id' => 'AUTHNET_CUST_' . uniqid(),
            'raw' => $params,
        ];
    }

    public function createSubscription(array $params): array
    {
        return [
            'success' => true,
            'subscription_id' => 'AUTHNET_SUB_' . uniqid(),
            'raw' => $params,
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return true;
    }
}
