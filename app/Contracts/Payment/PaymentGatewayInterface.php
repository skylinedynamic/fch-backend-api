<?php

namespace App\Contracts\Payment;

interface PaymentGatewayInterface
{
    /**
     * Initialize the payment gateway driver with configuration and target environment.
     *
     * @param array $config Gateway specific configuration
     * @param string $environment 'sandbox' or 'production'
     * @return self
     */
    public function initialize(array $config, string $environment = 'sandbox'): self;

    /**
     * Charge a payment intent or process one-time transaction.
     *
     * @param array $params
     * @return array Standardized response array ['success' => bool, 'transaction_id' => string, 'raw' => mixed]
     */
    public function charge(array $params): array;

    /**
     * Create or resolve a customer profile on the gateway.
     *
     * @param array $params ['email' => string, 'name' => string, ...]
     * @return array Standardized response array ['success' => bool, 'customer_id' => string, 'raw' => mixed]
     */
    public function createCustomer(array $params): array;

    /**
     * Create a recurring subscription.
     *
     * @param array $params ['customer_id' => string, 'plan_id' => string, ...]
     * @return array Standardized response array ['success' => bool, 'subscription_id' => string, 'raw' => mixed]
     */
    public function createSubscription(array $params): array;

    /**
     * Cancel an active subscription.
     *
     * @param string $subscriptionId
     * @return bool
     */
    public function cancelSubscription(string $subscriptionId): bool;
}
