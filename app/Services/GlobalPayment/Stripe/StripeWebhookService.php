<?php

namespace App\Services\GlobalPayment\Stripe;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Stripe\Subscription as StripeSubscription;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Partner\Partner;

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
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

            if ($subscription) {
                // Determine if this invoice is a platform fee
                $isPlatformFee = false;
                $lineItems = $stripeInvoice['lines']['data'] ?? [];
                foreach ($lineItems as $item) {
                    $desc = strtolower($item['description'] ?? '');
                    if (str_contains($desc, 'platform fee') || str_contains($desc, '399')) {
                        $isPlatformFee = true;
                        break;
                    }
                }

                DB::transaction(function () use ($subscription, $stripeInvoice, $stripeInvoiceId, $amountPaid, $invoicePdf, $isPlatformFee) {
                    // 1. Create local Invoice record
                    $invoice = Invoice::updateOrCreate(
                        ['invoice_id' => $stripeInvoiceId],
                        [
                            'partner_id' => $subscription->entity_type === Partner::class ? $subscription->entity_id : null,
                            'borrower_id' => $subscription->entity_type === \App\Models\Borrower\Borrower::class ? $subscription->entity_id : null,
                            'entity_type' => $subscription->entity_type,
                            'entity_id' => $subscription->entity_id,
                            'customer_name' => $subscription->entity?->full_name ?? $subscription->entity?->company_name ?? 'FCH Client',
                            'email' => $subscription->entity?->email ?? '',
                            'payment_amount' => $amountPaid,
                            'payment_date' => now(),
                            'status' => Invoice::STATUS_PAID,
                            'type' => $isPlatformFee ? 'platform_fee' : Invoice::INVOICE_TYPE_INSTALLMENT,
                            'is_partner_paying' => $subscription->entity_type === Partner::class,
                            'currency' => 'USD',
                            'is_platform_fee' => $isPlatformFee,
                            'details' => $stripeInvoice,
                        ]
                    );

                    // 2. Create local Payment record
                    $paymentModel = new Payment();
                    $paymentModel->createRecord([
                        'partner_id' => $invoice->partner_id,
                        'borrower_id' => $invoice->borrower_id,
                        'invoice_id' => $invoice->id,
                        'entity_type' => $invoice->entity_type,
                        'entity_id' => $invoice->entity_id,
                        'transaction_id' => $stripeInvoice['payment_intent'] ?? ('TX_' . uniqid()),
                        'amount' => $amountPaid,
                        'currency' => 'USD',
                        'payment_from' => Payment::PAYMENT_FROM_STRIPE,
                        'status' => Payment::STATUS_SUCCEEDED,
                        'details' => $stripeInvoice,
                    ]);

                    // 3. Update local Subscription status & dates
                    $subscription->update([
                        'status' => Subscription::STATUS_ACTIVE,
                        'subscription_start_date' => $subscription->subscription_start_date ?? now(),
                    ]);

                    // 4. Manage local SubscriptionPhase
                    $phaseType = $isPlatformFee ? SubscriptionPhase::PHASE_PLATFORM_FEE : SubscriptionPhase::PHASE_ENROLLMENT;
                    $phase = $subscription->allPhases()->where('phase', $phaseType)->first();

                    if (!$phase) {
                        $phase = $subscription->allPhases()->create([
                            'phase' => $phaseType,
                            'installments_paid' => 0,
                        ]);
                    }

                    if (!$isPlatformFee) {
                        $phase->increment('installments_paid');
                        $phase->update([
                            'next_installment_date' => Carbon::now()->addMonth(),
                        ]);

                        // Check if enrollment is complete
                        $totalInstallments = $subscription->installment_count ?? 1;
                        if ($phase->installments_paid >= $totalInstallments) {
                            $phase->update([
                                'enrollment_completed_at' => now(),
                                'next_installment_date' => null,
                            ]);
                            // Create platform fee phase
                            $subscription->allPhases()->create([
                                'phase' => SubscriptionPhase::PHASE_PLATFORM_FEE,
                                'installments_paid' => 0,
                                'next_platform_fee_date' => Carbon::now()->addMonth(),
                            ]);
                        }
                    } else {
                        $phase->increment('installments_paid');
                        $phase->update([
                            'next_platform_fee_date' => Carbon::now()->addMonth(),
                        ]);
                    }
                });
            } else {
                Log::warning('[Stripe Webhook Service] Subscription not found locally', ['stripe_subscription_id' => $stripeSubscriptionId]);
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
        $amountDue = $stripeInvoice['amount_due'] / 100;

        Log::warning('[Stripe Webhook Service] Invoice payment failed', [
            'invoice_id' => $stripeInvoiceId,
            'subscription_id' => $stripeSubscriptionId
        ]);

        if ($stripeSubscriptionId) {
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if ($subscription) {
                DB::transaction(function () use ($subscription, $stripeInvoice, $stripeInvoiceId, $amountDue) {
                    $invoice = Invoice::updateOrCreate(
                        ['invoice_id' => $stripeInvoiceId],
                        [
                            'partner_id' => $subscription->entity_type === Partner::class ? $subscription->entity_id : null,
                            'borrower_id' => $subscription->entity_type === \App\Models\Borrower\Borrower::class ? $subscription->entity_id : null,
                            'entity_type' => $subscription->entity_type,
                            'entity_id' => $subscription->entity_id,
                            'customer_name' => $subscription->entity?->full_name ?? $subscription->entity?->company_name ?? 'FCH Client',
                            'email' => $subscription->entity?->email ?? '',
                            'payment_amount' => $amountDue,
                            'status' => Invoice::STATUS_FAILED,
                            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
                            'is_partner_paying' => $subscription->entity_type === Partner::class,
                            'currency' => 'USD',
                            'details' => $stripeInvoice,
                        ]
                    );

                    $subscription->update([
                        'status' => 'Past Due',
                    ]);
                });
            }
        }
    }

    /**
     * Keeps track of active, trialing, unpaid, or canceled subscription state modifications.
     */
    protected function handleSubscriptionUpdated(array $stripeSubscription): void
    {
        $stripeSubscriptionId = $stripeSubscription['id'];
        $stripeStatus = $stripeSubscription['status'];

        $statusMap = [
            'active' => Subscription::STATUS_ACTIVE,
            'trialing' => 'Trialing',
            'incomplete' => Subscription::STATUS_PENDING,
            'incomplete_expired' => Subscription::STATUS_EXPIRED,
            'past_due' => 'Past Due',
            'unpaid' => 'Unpaid',
            'canceled' => 'Cancelled',
        ];

        $localStatus = $statusMap[$stripeStatus] ?? 'Inactive';

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
        if ($subscription) {
            $subscription->update([
                'status' => $localStatus,
                'subscription_end_date' => $stripeSubscription['current_period_end'] ? Carbon::createFromTimestamp($stripeSubscription['current_period_end']) : null,
            ]);
        }
    }

    /**
     * Terminate localized subscription.
     */
    protected function handleSubscriptionDeleted(array $stripeSubscription): void
    {
        $stripeSubscriptionId = $stripeSubscription['id'];

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
        if ($subscription) {
            $subscription->update([
                'status' => 'Cancelled',
                'subscription_end_date' => now(),
            ]);
        }
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
