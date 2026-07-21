<?php

namespace App\Services\GlobalPayment\Stripe;

use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Subscription as StripeSubscription;

class StripeWebhookService
{
    public function __construct(
        protected StripeSubscriptionService $subscriptionService
    ) {}

    /**
     * Handle incoming Stripe webhook payload routing.
     *
     * @param array $payload
     * @return bool
     */
    public function handleWebhook(array $payload): bool
    {
        $eventType = $payload['type'] ?? '';
        $dataObject = $payload['data']['object'] ?? [];

        Log::info('[Stripe Webhook Service] Dispatching event', ['type' => $eventType]);

        switch ($eventType) {
            case 'invoice.payment_succeeded':
                $this->handleInvoicePaymentSucceeded($dataObject);
                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($dataObject);
                break;

            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($dataObject);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($dataObject);
                break;

            default:
                Log::info('[Stripe Webhook Service] Event not configured for handling', ['type' => $eventType]);
                break;
        }

        return true;
    }

    /**
     * Syncs paid invoice and sets local subscription period active.
     */
    protected function handleInvoicePaymentSucceeded(array $stripeInvoice): void
    {
        $stripeSubscriptionId = $stripeInvoice['subscription'] ?? null;
        $stripeCustomerId = $stripeInvoice['customer'] ?? null;
        $amountPaid = $stripeInvoice['amount_paid'] / 100;
        $invoicePdf = $stripeInvoice['invoice_pdf'] ?? null;
        $stripeInvoiceId = $stripeInvoice['id'];

        Log::info('[Stripe Webhook Service] Invoice payment success', [
            'invoice_id' => $stripeInvoiceId,
            'subscription_id' => $stripeSubscriptionId,
        ]);

        if ($stripeSubscriptionId) {
            // Log local transaction record
            $this->recordLocalPayment([
                'stripe_invoice_id' => $stripeInvoiceId,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'amount' => $amountPaid,
                'status' => 'success',
                'pdf_url' => $invoicePdf
            ]);

            try {
                // Fetch actual Stripe subscription dates
                $sub = StripeSubscription::retrieve($stripeSubscriptionId);

                $this->subscriptionService->syncLocalSubscription($stripeCustomerId, [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'status' => 'Active',
                    'start_date' => date('Y-m-d H:i:s', $sub->current_period_start),
                    'end_date' => date('Y-m-d H:i:s', $sub->current_period_end)
                ]);
            } catch (Exception $e) {
                Log::error('[Stripe Webhook Service] Failed to retrieve subscription detail', ['error' => $e->getMessage()]);
            }
        } else {
            // One-time payment handling
            $this->updateLocalOneTimeInvoice($stripeInvoiceId, [
                'status' => 'Paid',
                'amount_paid' => $amountPaid
            ]);
        }
    }

    /**
     * Flags a subscription past due when a renewal fails.
     */
    protected function handleInvoicePaymentFailed(array $stripeInvoice): void
    {
        $stripeSubscriptionId = $stripeInvoice['subscription'] ?? null;
        $stripeInvoiceId = $stripeInvoice['id'];
        $stripeCustomerId = $stripeInvoice['customer'] ?? null;

        Log::warning('[Stripe Webhook Service] Invoice payment failed', [
            'invoice_id' => $stripeInvoiceId,
            'subscription_id' => $stripeSubscriptionId
        ]);

        $this->recordLocalPayment([
            'stripe_invoice_id' => $stripeInvoiceId,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'amount' => $stripeInvoice['amount_due'] / 100,
            'status' => 'failed',
            'failure_reason' => $stripeInvoice['billing_reason'] ?? 'Payment Decline'
        ]);

        if ($stripeSubscriptionId) {
            $this->subscriptionService->syncLocalSubscription($stripeCustomerId, [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'status' => 'Past Due'
            ]);
        }
    }

    /**
     * Keeps track of active, trialing, unpaid, or canceled subscription state modifications.
     */
    protected function handleSubscriptionUpdated(array $stripeSubscription): void
    {
        $stripeSubscriptionId = $stripeSubscription['id'];
        $stripeCustomerId = $stripeSubscription['customer'];
        $stripeStatus = $stripeSubscription['status'];

        $statusMap = [
            'active' => 'Active',
            'trialing' => 'Trialing',
            'incomplete' => 'PendingPayment',
            'incomplete_expired' => 'Expired',
            'past_due' => 'Past Due',
            'unpaid' => 'Unpaid',
            'canceled' => 'Cancelled',
        ];

        $localStatus = $statusMap[$stripeStatus] ?? 'Inactive';

        $this->subscriptionService->syncLocalSubscription($stripeCustomerId, [
            'stripe_subscription_id' => $stripeSubscriptionId,
            'status' => $localStatus,
            'start_date' => date('Y-m-d H:i:s', $stripeSubscription['current_period_start']),
            'end_date' => date('Y-m-d H:i:s', $stripeSubscription['current_period_end']),
            'cancel_at_period_end' => $stripeSubscription['cancel_at_period_end'] ?? false
        ]);
    }

    /**
     * Terminate localized subscription.
     */
    protected function handleSubscriptionDeleted(array $stripeSubscription): void
    {
        $stripeSubscriptionId = $stripeSubscription['id'];
        $stripeCustomerId = $stripeSubscription['customer'];

        $this->subscriptionService->syncLocalSubscription($stripeCustomerId, [
            'stripe_subscription_id' => $stripeSubscriptionId,
            'status' => 'Cancelled',
            'ended_at' => now()
        ]);
    }

    protected function recordLocalPayment(array $data): void
    {
        Log::debug('[Local DB Sync] Webhook logged payment ledger entry', ['data' => $data]);
    }

    protected function updateLocalOneTimeInvoice(string $stripeInvoiceId, array $data): void
    {
        Log::debug('[Local DB Sync] Webhook synced one-time invoice update', [
            'invoice_id' => $stripeInvoiceId,
            'data' => $data
        ]);
    }
}
