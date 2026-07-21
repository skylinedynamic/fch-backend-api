<?php

namespace App\Services\GlobalPayment\Stripe;

use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Subscription as StripeSubscription;

class StripeSubscriptionService
{
    /**
     * Create a Stripe Subscription.
     * The billing frequency (e.g. 6 months vs 1 month) is driven by the Stripe Price ID configuration.
     *
     * @param string $customerId
     * @param string $priceId
     * @return array
     */
    public function createSubscription(string $customerId, string $priceId): array
    {
        $subscription = StripeSubscription::create([
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
        ]);

        // Sync pending subscription state locally
        $this->syncLocalSubscription($customerId, [
            'stripe_subscription_id' => $subscription->id,
            'status' => 'Pending',
            'billing_cycle' => $subscription->items->data[0]->price->recurring->interval ?? 'recurring',
        ]);

        return [
            'stripe_subscription_id' => $subscription->id,
            'status' => $subscription->status, // 'incomplete'
            'client_secret' => $subscription->latest_invoice->payment_intent->client_secret ?? null,
            'invoice_id' => $subscription->latest_invoice->id ?? null,
        ];
    }

    /**
     * Placeholder: Sync subscription data with the local database.
     *
     * @param string $stripeCustomerId
     * @param array $data
     * @return void
     */
    public function syncLocalSubscription(string $stripeCustomerId, array $data): void
    {
        Log::debug('[Stripe Subscription Service] Syncing local subscription record', [
            'stripe_customer' => $stripeCustomerId,
            'data' => $data
        ]);
        // Map changes to local database models/tables here
    }
}
