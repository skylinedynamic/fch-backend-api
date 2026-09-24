<?php

namespace App\Services\GlobalPayment\Drivers;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Invoice\Invoice;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use App\Support\Payment\PaymentCredentials;
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
        $this->environment = PaymentCredentials::normalizeMode($environment);

        $this->apiKey = PaymentCredentials::secretKey($config, $this->environment);

        if (!$this->apiKey) {
            throw new RuntimeException("Stripe secret key not configured for {$this->environment} mode.");
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
            $stripeCustomerData,
            $this->environment
        );

        // 3. Attach payment method and set as default (if a token is provided)
        $paymentMethodId = null;
        $cardDetails = ['name' => null, 'card_no' => null];
        if (!empty($params['payment_token']) && str_starts_with($params['payment_token'], 'pm_')) {
            $paymentMethodId = $params['payment_token'];
            $customerService->setDefaultPaymentMethod($customerId, $paymentMethodId);
            $cardDetails = $customerService->fetchCardDetails($paymentMethodId);
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

        if (!$cardDetails['card_no'] && !empty($intent->payment_method)) {
            $cardDetails = $customerService->fetchCardDetails($intent->payment_method);
        }

        $isSandbox = $this->environment === PaymentProvider::MODE_SANDBOX;
        $cardName = !empty($cardDetails['name']) ? $cardDetails['name'] : ($isSandbox ? 'Sandbox Card' : null);
        $cardNo = $cardDetails['card_no'];

        // Persist the Stripe Customer this charge actually used.
        //
        // resolveCustomer() discards a customer belonging to the other Stripe
        // account and resolves a fresh one, so after a mode switch the id used
        // here is NOT the one on file. Writing back only when the record looked
        // empty left the stale id in place, and the next payment repeated the
        // whole rediscovery - so the record is also rewritten whenever the
        // customer or the mode has moved on.
        //
        // Rewriting on a mode change is what backfills `payment_mode` onto rows
        // saved before it was recorded, which is what lets the cross-account
        // guards recognize them later.
        $stored = is_array($payer?->stripe_customer_id) ? $payer->stripe_customer_id : [];
        $storedCustomerId = $stored['customer_id'] ?? (is_string($payer?->stripe_customer_id) ? $payer->stripe_customer_id : null);
        $customerMoved = $storedCustomerId !== $customerId;

        $isStale = $customerMoved || ($stored['payment_mode'] ?? null) !== $this->environment;
        $isIncomplete = empty($storedCustomerId) || empty($stored['card_no'] ?? null);

        if ($payer && ($isStale || $isIncomplete)) {
            // Card details already on file describe the OLD customer's card, so
            // they are only worth keeping when the customer itself has not moved.
            $keepIfSameCustomer = fn (string $key) => $customerMoved ? null : ($stored[$key] ?? null);

            $payer->update([
                'default_payment_method' => PaymentProvider::STRIPE,
                'stripe_customer_id' => [
                    'customer_id'  => $customerId,
                    'name'         => $cardName ?: $keepIfSameCustomer('name'),
                    'card_no'      => $cardNo ?: $keepIfSameCustomer('card_no'),
                    'payment_mode' => $this->environment,
                ],
            ]);
        }

        return [
            'success' => in_array($intent->status, ['succeeded', 'requires_capture']),
            'transaction_id' => $intent->id,
            'status' => $intent->status,
            'client_secret' => $intent->client_secret,
            'customer_id' => $customerId,
            'card_name' => $cardName,
            'card_no' => $cardNo,
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
        $customer = StripeCustomer::retrieve($customerId);
        $defaultPaymentMethod = $customer->invoice_settings->default_payment_method ?? null;

        if (!$defaultPaymentMethod) {
            $methods = \Stripe\PaymentMethod::all([
                'customer' => $customerId,
                'type' => 'card',
            ]);
            $defaultPaymentMethod = $methods->data[0]->id ?? null;
        }

        $params = [
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'description' => $description,
            'confirm' => true,
            'off_session' => true,
        ];

        if ($defaultPaymentMethod) {
            $params['payment_method'] = $defaultPaymentMethod;
        } else {
            $params['automatic_payment_methods'] = [
                'enabled' => true,
                'allow_redirects' => 'never',
            ];
        }

        $intent = PaymentIntent::create($params);

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
