<?php

namespace App\Services\GlobalPayment;

use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use App\Services\GlobalPayment\Stripe\StripeSubscriptionService;
use App\Services\GlobalPayment\Stripe\StripeWebhookService;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent as StripePaymentIntent;

/**
 * Class StripePaymentService
 *
 * Serves as the high-level facade orchestrating Stripe customers, subscriptions,
 * one-time checkout intents, and webhook event handling.
 */
class StripePaymentService
{
    public function __construct(
        protected StripeCustomerService $customerService,
        protected StripeSubscriptionService $subscriptionService,
        protected StripeWebhookService $webhookService
    ) {
        // Initialize Stripe credentials
        Stripe::setApiKey(config('services.stripe.secret') ?? env('STRIPE_SECRET_KEY'));
    }

    /**
     * Coordinates the checkout payment workflow for subscriptions or one-time transactions.
     *
     * @param array $params [
     *   'email' => string,
     *   'name' => string,
     *   'payment_method_id' => string,
     *   'payment_type' => 'recurring' | 'one_time',
     *   'stripe_price_id' => string, // required for recurring
     *   'amount' => float,           // required for one_time
     *   'currency' => string
     * ]
     * @return array Response details for the client
     * @throws Exception
     */
    public function processPaymentWorkflow(array $params): array
    {
        try {
            Log::info('[Stripe Payment Service] Resolving payment workflow', [
                'type' => $params['payment_type'] ?? null,
                'email' => $params['email'] ?? null,
            ]);

            // 1. Resolve or create customer profile on Stripe
            $customerId = $this->customerService->resolveCustomer(
                $params['email'],
                $params['name'],
                $params['stripe_customer_id'] ?? null
            );

            // 2. Assign default payment source
            if (!empty($params['payment_method_id'])) {
                $this->customerService->setDefaultPaymentMethod($customerId, $params['payment_method_id']);
            }

            // 3. Process charge or subscription setup
            if ($params['payment_type'] === 'recurring') {
                if (empty($params['stripe_price_id'])) {
                    throw new Exception('stripe_price_id is required for recurring subscription payments.');
                }
                return $this->subscriptionService->createSubscription($customerId, $params['stripe_price_id']);
            } else {
                if (empty($params['amount'])) {
                    throw new Exception('amount is required for one-time payment charges.');
                }
                return $this->initiateOneTimePayment($customerId, $params['amount'], $params['currency'] ?? 'usd');
            }
        } catch (Exception $e) {
            Log::error('[Stripe Payment Service] Checkout flow error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Entry point to dispatch webhook data to the handler service.
     *
     * @param array $payload Webhook request JSON payload
     * @return bool
     */
    public function handleWebhook(array $payload): bool
    {
        return $this->webhookService->handleWebhook($payload);
    }

    /**
     * Process one-time payment intent.
     *
     * @param string $customerId
     * @param float $amount
     * @param string $currency
     * @return array
     */
    protected function initiateOneTimePayment(string $customerId, float $amount, string $currency): array
    {
        $amountInCents = (int) ($amount * 100);

        $paymentIntent = StripePaymentIntent::create([
            'customer' => $customerId,
            'amount' => $amountInCents,
            'currency' => $currency,
            'description' => 'One-time Payment',
            'confirm' => true,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
        ]);

        $this->syncLocalOneTimeInvoice($customerId, [
            'stripe_payment_intent_id' => $paymentIntent->id,
            'amount' => $amount,
            'status' => $paymentIntent->status === 'succeeded' ? 'Paid' : 'Pending',
        ]);

        return [
            'type' => 'one_time',
            'payment_intent_id' => $paymentIntent->id,
            'status' => $paymentIntent->status,
            'client_secret' => $paymentIntent->client_secret,
        ];
    }

    protected function syncLocalOneTimeInvoice(string $stripeCustomerId, array $data): void
    {
        Log::debug('[Local DB Sync] Creating/syncing local representation of one-time invoice', [
            'stripe_customer' => $stripeCustomerId,
            'data' => $data
        ]);
    }

    /**
     * Synchronize local customer email updates to Stripe.
     *
     * @param string $stripeCustomerId
     * @param string $newEmail
     * @return void
     */
    public function updateCustomerEmail(string $stripeCustomerId, string $newEmail): void
    {
        $this->customerService->updateCustomerEmail($stripeCustomerId, $newEmail);
    }
}
