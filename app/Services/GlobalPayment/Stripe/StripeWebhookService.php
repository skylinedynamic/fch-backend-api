<?php

namespace App\Services\GlobalPayment\Stripe;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Subscription\Subscription;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Partner\Partner;

/**
 * Class StripeWebhookService
 *
 * Handles Stripe webhook events in the application-led billing model.
 *
 * Relevant events in this model:
 *  - payment_intent.succeeded  → Mark invoice as Paid and advance subscription phase
 *  - payment_intent.payment_failed → Mark invoice as Failed and subscription as Past Due
 *
 * NOTE: invoice.payment_succeeded / invoice.payment_failed / customer.subscription.*
 * events are NOT used because we no longer create Stripe Subscriptions or Invoices.
 * All billing is managed locally and charged via one-time PaymentIntents.
 */
class StripeWebhookService
{
    /**
     * Dispatch incoming Stripe webhook payload to the correct handler.
     *
     * @param array $payload
     * @return bool
     */
    public function handleWebhook(array $payload): bool
    {
        $eventType  = $payload['type'] ?? '';
        $dataObject = $payload['data']['object'] ?? [];

        Log::info('[Stripe Webhook Service] Dispatching event', ['type' => $eventType]);

        switch ($eventType) {

            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($dataObject);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($dataObject);
                break;

            default:
                Log::info('[Stripe Webhook Service] Event not configured for handling', ['type' => $eventType]);
                break;
        }

        return true;
    }

    /**
     * Handle a successful PaymentIntent.
     *
     * Finds the local invoice by payment_intent ID and marks it as Paid.
     * Also creates a Payment ledger entry and advances the subscription phase.
     */
    protected function handlePaymentIntentSucceeded(array $intent): void
    {
        $intentId   = $intent['id'] ?? null;
        $customerId = $intent['customer'] ?? null;
        $amountPaid = ($intent['amount_received'] ?? 0) / 100;

        Log::info('[Stripe Webhook Service] PaymentIntent succeeded', [
            'intent_id'   => $intentId,
            'customer_id' => $customerId,
        ]);

        // Find the local invoice linked to this payment intent via Payment records
        $payment = Payment::where('transaction_id', $intentId)->first();

        if (!$payment) {
            // Payment may already be recorded synchronously by the driver. Log and exit.
            Log::info('[Stripe Webhook Service] No local payment record found for intent (may be already synced).', ['intent_id' => $intentId]);
            return;
        }

        $invoice = Invoice::find($payment->invoice_id);
        if (!$invoice) {
            Log::warning('[Stripe Webhook Service] Invoice not found for payment intent.', ['intent_id' => $intentId]);
            return;
        }

        // Ensure idempotency: only process if not already paid
        if ($invoice->status === Invoice::STATUS_PAID) {
            Log::info('[Stripe Webhook Service] Invoice already marked as Paid. Skipping.', ['invoice_id' => $invoice->id]);
            return;
        }

        DB::transaction(function () use ($invoice, $payment, $amountPaid, $intentId, $intent) {
            $invoice->update([
                'status'       => Invoice::STATUS_PAID,
                // payment_date is a DATE column - store the calendar date.
                'payment_date' => today(),
            ]);

            // Update payment record status if needed
            $payment->update(['status' => Payment::STATUS_SUCCEEDED]);

            Log::info('[Stripe Webhook Service] Invoice marked as Paid via webhook.', ['invoice_id' => $invoice->id]);
        });
    }

    /**
     * Handle a failed PaymentIntent.
     *
     * Marks the related invoice as Failed and sets the subscription status to Past Due.
     */
    protected function handlePaymentIntentFailed(array $intent): void
    {
        $intentId       = $intent['id'] ?? null;
        $failureMessage = $intent['last_payment_error']['message'] ?? 'Unknown reason';

        Log::warning('[Stripe Webhook Service] PaymentIntent failed', [
            'intent_id' => $intentId,
            'reason'    => $failureMessage,
        ]);

        $payment = Payment::where('transaction_id', $intentId)->first();
        if (!$payment) {
            Log::info('[Stripe Webhook Service] No local payment record for failed intent.', ['intent_id' => $intentId]);
            return;
        }

        $invoice = Invoice::find($payment->invoice_id);
        if (!$invoice) {
            return;
        }

        DB::transaction(function () use ($invoice, $payment) {
            $invoice->update(['status' => Invoice::STATUS_FAILED]);
            $payment->update(['status' => Payment::STATUS_FAILED ?? 'Failed']);

            // Mark the subscription as Past Due so the user loses access
            $subscription = Subscription::where('entity_type', $invoice->entity_type)
                ->where('entity_id', $invoice->entity_id)
                ->first();

            if ($subscription) {
                $subscription->update(['status' => 'Past Due']);
            }
        });
    }
}
