<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Invoice\Invoice;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use App\Services\GlobalPayment\StripePaymentService;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Stripe\PaymentMethod as StripePaymentMethod;
use RuntimeException;

class StripePaymentDriver implements PaymentGatewayInterface
{
    protected array $config = [];
    protected string $environment = PaymentProvider::MODE_SANDBOX;
    protected ?string $apiKey = null;

    public function initialize(array $config, string $environment = PaymentProvider::MODE_SANDBOX): self
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
        $currency = strtolower($params['currency'] ?? 'USD');

        // 1. Resolve Stripe Customer ID
        $invoice = Invoice::find($params['invoice_id']);
        if (!$invoice) {
            throw new RuntimeException("Invoice not found.");
        }

        $payer = $invoice->entity;
        $stripeCustomerId = $payer ? $payer->stripe_customer_id : null;

        $customerService = app(StripeCustomerService::class);
        $customerId = $customerService->resolveCustomer(
            $invoice->email,
            $invoice->customer_name ?? 'FCH Client',
            $stripeCustomerId
        );

        if ($payer && $stripeCustomerId !== $customerId) {
            $payer->update(['stripe_customer_id' => $customerId]);
        }

        // 2. Resolve Stripe PaymentMethod ID
        $paymentMethodId = null;
        if (!empty($params['payment_token']) && str_starts_with($params['payment_token'], 'pm_')) {
            $paymentMethodId = $params['payment_token'];
        }

        if ($paymentMethodId) {
            // Attach payment method and set as default for the customer
            $customerService->setDefaultPaymentMethod($customerId, $paymentMethodId);
        }

        // 3. Find if there is an associated subscription
        $subscription = Subscription::where('entity_type', $invoice->entity_type)
            ->where('entity_id', $invoice->entity_id)
            ->first();

        if ($subscription) {
            // Subscription schedule flow
            $stripePaymentService = app(StripePaymentService::class);

            // Check if subscription schedule already exists
            if ($subscription->stripe_subscription_id) {
                $existingSub = StripeSubscription::retrieve([
                    'id' => $subscription->stripe_subscription_id,
                    'expand' => ['latest_invoice.payment_intent']
                ]);

                if ($existingSub->status === 'incomplete' && isset($existingSub->latest_invoice->payment_intent)) {
                    $intent = $existingSub->latest_invoice->payment_intent->confirm();
                } else {
                    $intent = $existingSub->latest_invoice->payment_intent;
                }

                // Sync local subscription status
                $subscription->update([
                    'status' => Subscription::STATUS_ACTIVE,
                    'subscription_start_date' => $subscription->subscription_start_date ?? now(),
                ]);

                return [
                    'success' => in_array($existingSub->status, ['active', 'trialing']) || ($intent && $intent->status === 'succeeded'),
                    'transaction_id' => $intent->id ?? $existingSub->id,
                    'status' => $existingSub->status,
                    'raw' => $existingSub->toArray(),
                ];
            }

            // Create new subscription schedule
            $paramsSchedule = [
                'email' => $invoice->email,
                'name' => $invoice->customer_name ?? 'FCH Client',
                'stripe_customer_id' => $customerId,
                'enrollment_type' => $subscription->enrollment_type,
                'payable_amount' => (float) $subscription->payable_amount,
                'down_payment' => (float) $subscription->down_payment,
                'installment_months' => (int) $subscription->installment_count,
                'monthly_platform_fee' => (float) $subscription->monthly_platform_fee,
                'secret_key' => $this->apiKey,
            ];

            $result = $stripePaymentService->initiateSubscriptionSchedule($paramsSchedule);

            // Update subscription stripe_subscription_id
            if (!empty($result['stripe_subscription_id'])) {
                $subscription->update([
                    'stripe_subscription_id' => $result['stripe_subscription_id'],
                    'status' => Subscription::STATUS_ACTIVE,
                    'subscription_start_date' => $subscription->subscription_start_date ?? now(),
                ]);
            }

            // Retrieve subscription and verify its invoice status
            $existingSub = StripeSubscription::retrieve([
                'id' => $result['stripe_subscription_id'],
                'expand' => ['latest_invoice.payment_intent']
            ]);

            return [
                'success' => in_array($existingSub->status, ['active', 'trialing']) || ($existingSub->latest_invoice->payment_intent->status ?? '') === 'succeeded',
                'transaction_id' => $existingSub->latest_invoice->payment_intent->id ?? $existingSub->id,
                'status' => $existingSub->status,
                'raw' => $existingSub->toArray(),
            ];

        } else {
            // One-time payment intent flow
            $intentParams = [
                'amount' => $amountInCents,
                'currency' => $currency,
                'description' => $params['description'] ?? 'Payment Charge',
                'confirm' => true,
                'customer' => $customerId,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
            ];

            if ($paymentMethodId) {
                $intentParams['payment_method'] = $paymentMethodId;
            }

            $intent = PaymentIntent::create($intentParams);

            return [
                'success' => in_array($intent->status, ['succeeded', 'requires_capture']),
                'transaction_id' => $intent->id,
                'status' => $intent->status,
                'raw' => $intent->toArray(),
            ];
        }
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
