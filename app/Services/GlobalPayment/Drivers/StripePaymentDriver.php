<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use RuntimeException;

class StripePaymentDriver implements PaymentGatewayInterface
{
    protected array $config = [];
    protected string $environment = 'sandbox';
    protected ?string $apiKey = null;

    public function initialize(array $config, string $environment = 'sandbox'): self
    {
        $this->config = $config;
        $this->environment = $environment;

        $envConfig = $config[$environment] ?? $config;
        $this->apiKey = $envConfig['secret_key'] ?? ($config['stripe_secret_' . $environment] ?? null);

        if (!$this->apiKey) {
            throw new RuntimeException("Stripe secret key not configured for {$environment} mode.");
        }

        Stripe::setApiKey($this->apiKey);

        return $this;
    }

    public function charge(array $params): array
    {
        $amountInCents = (int) round(($params['amount'] ?? 0) * 100);
        $currency = strtolower($params['currency'] ?? 'usd');

        $intentParams = [
            'amount' => $amountInCents,
            'currency' => $currency,
            'description' => $params['description'] ?? 'Payment Charge',
            'confirm' => $params['confirm'] ?? true,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ];

        if (!empty($params['payment_method_id'])) {
            $intentParams['payment_method'] = $params['payment_method_id'];
        }

        if (!empty($params['customer_id'])) {
            $intentParams['customer'] = $params['customer_id'];
        }

        $intent = PaymentIntent::create($intentParams);

        return [
            'success' => in_array($intent->status, ['succeeded', 'requires_capture']),
            'transaction_id' => $intent->id,
            'status' => $intent->status,
            'raw' => $intent->toArray(),
        ];
    }

    public function createCustomer(array $params): array
    {
        $customer = StripeCustomer::create([
            'email' => $params['email'] ?? null,
            'name' => $params['name'] ?? null,
        ]);

        return [
            'success' => true,
            'customer_id' => $customer->id,
            'raw' => $customer->toArray(),
        ];
    }

    public function createSubscription(array $params): array
    {
        $subscription = StripeSubscription::create([
            'customer' => $params['customer_id'],
            'items' => [
                ['price' => $params['plan_id'] ?? $params['price_id']],
            ],
        ]);

        return [
            'success' => in_array($subscription->status, ['active', 'trialing']),
            'subscription_id' => $subscription->id,
            'raw' => $subscription->toArray(),
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        $subscription = StripeSubscription::retrieve($subscriptionId);
        $canceled = $subscription->cancel();

        return $canceled->status === 'canceled';
    }
}
