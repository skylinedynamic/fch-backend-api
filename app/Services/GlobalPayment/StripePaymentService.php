<?php

namespace App\Services\GlobalPayment;

use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use App\Services\GlobalPayment\Stripe\StripeWebhookService;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;

/**
 * Class StripePaymentService
 *
 * High-level facade for Stripe operations in the application-led billing model.
 * This service handles customer management and webhook dispatching only.
 *
 * Billing cycles, installment schedules, and platform fees are controlled by the
 * application's local database. Charges are executed via StripePaymentDriver
 * (one-time PaymentIntents or off-session charges via the auto-charge cron job).
 *
 * NOTE: Stripe Subscription Schedules are NOT used.
 */
class StripePaymentService
{
    public function __construct(
        protected StripeCustomerService $customerService,
        protected StripeWebhookService $webhookService
    ) {
        Stripe::setApiKey(config('services.stripe.secret') ?? env('STRIPE_SECRET_KEY'));
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
