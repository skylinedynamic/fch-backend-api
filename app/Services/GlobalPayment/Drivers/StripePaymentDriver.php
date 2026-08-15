<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Invoice\Invoice;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Stripe\PaymentMethod as StripePaymentMethod;
use RuntimeException;

/**
 * Application-Led Stripe Payment Driver
 *
 * This driver is responsible for:
 * 1. Resolving/creating a Stripe Customer and attaching the payment method.
 * 2. Charging the customer a one-time PaymentIntent for the given invoice amount.
 *
 * Stripe Subscription Schedules are NOT used in this architecture.
 * The application (Laravel) manages billing cycles, installments, and platform fees entirely.
 * Due payments are automatically charged off-session by the `subscriptions:process-all` cron job.
 */
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

    /**
     * Charge a one-time PaymentIntent for the given invoice amount.
     *
     * Steps:
     *  1. Resolve or create the Stripe Customer using the payer's email.
     *  2. Attach and set the provided payment method (pm_xxx) as the default on the customer.
     *  3. Create and confirm a PaymentIntent for the invoice amount.
     *
     * @param array $params
     * @return array
     */
    public function charge(array $params): array
    {
        $amountInCents = (int) round(($params['amount'] ?? 0) * 100);
        $currency = strtolower($params['currency'] ?? 'usd');

        // 1. Resolve invoice and payer
        $invoice = Invoice::find($params['invoice_id']);
        if (!$invoice) {
            throw new RuntimeException("Invoice not found.");
        }

        $payer = $invoice->entity;
        $stripeCustomerData = $payer?->stripe_customer_id;
        $stripeCustomerIdRaw = $payer?->stripe_customer_id_raw;

        // 2. Resolve or create Stripe Customer
        $customerService = app(StripeCustomerService::class);
        $customerId = $customerService->resolveCustomer(
            $invoice->email,
            $invoice->customer_name ?? 'Client',
            $stripeCustomerData
        );

        // Persist Stripe Customer ID only when the payer has no saved card details yet.
        // Card details can only be updated from the Billing Details page.
        if ($payer && empty($stripeCustomerIdRaw)) {
            $payer->update(['stripe_customer_id' => [
                'customer_id' => $customerId,
                'name'        => null,
                'card_no'     => null,
            ]]);
        }

        // 3. Attach payment method and set as default (if a token is provided)
        $paymentMethodId = null;
        if (!empty($params['payment_token']) && str_starts_with($params['payment_token'], 'pm_')) {
            $paymentMethodId = $params['payment_token'];
            $customerService->setDefaultPaymentMethod($customerId, $paymentMethodId);
        }

        // 4. Build PaymentIntent parameters
        $intentParams = [
            'amount' => $amountInCents,
            'currency' => $currency,
            'customer' => $customerId,
            'description' => $params['description'] ?? "Payment for Invoice #{$invoice->invoice_id}",
            'confirm' => true,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ];

        if ($paymentMethodId) {
            $intentParams['payment_method'] = $paymentMethodId;
        }

        // 5. Create & confirm the PaymentIntent
        $intent = PaymentIntent::create($intentParams);

        return [
            'success' => in_array($intent->status, ['succeeded', 'requires_capture']),
            'transaction_id' => $intent->id,
            'status' => $intent->status,
            'client_secret' => $intent->client_secret,
            'raw' => $intent->toArray(),
        ];
    }

    /**
     * Charge a saved card off-session (used by the auto-charge cron job).
     * The customer must already have a default payment method attached on Stripe.
     *
     * @param string $customerId   Stripe Customer ID (cus_xxx)
     * @param int    $amountCents  Amount in cents
     * @param string $currency
     * @param string $description
     * @return array
     */
    public function chargeOffSession(string $customerId, int $amountCents, string $currency = 'usd', string $description = 'Subscription Auto-Charge'): array
    {
        $intent = PaymentIntent::create([
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'description' => $description,
            'confirm' => true,
            'off_session' => true,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ]);

        return [
            'success' => $intent->status === 'succeeded',
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
        // Not used in the application-led model.
        // Billing cycles are managed locally and charged via chargeOffSession().
        throw new RuntimeException("Stripe Subscriptions are not used in this system. Use the application-led billing model instead.");
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        // Not used in the application-led model.
        throw new RuntimeException("Stripe Subscriptions are not used in this system. Subscription cancellation is managed locally.");
    }
}
