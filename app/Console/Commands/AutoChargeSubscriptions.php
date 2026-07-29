<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\CronJobFailureMail;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPhase;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\GlobalPayment\PaymentGatewayManager;

/**
 * Command: subscriptions:auto-charge
 *
 * Application-Led Subscription Auto-Charge Cron Job.
 *
 * This command runs daily and automatically charges saved customer payment methods
 * for any subscription installments or platform fees that are due.
 *
 * Flow:
 *  1. Find all active subscriptions with an Unpaid invoice due today or earlier.
 *  2. Retrieve the entity's saved Stripe Customer ID and configured payment provider.
 *  3. Execute an off-session Stripe charge via the gateway driver.
 *  4. On success: mark invoice as Paid and advance the local subscription phase.
 *  5. On failure: mark invoice as Failed, set subscription to Past Due, log error.
 */
class AutoChargeSubscriptions extends Command
{
    protected $signature = 'subscriptions:auto-charge';

    protected $description = 'Automatically charge due subscription installments and platform fees via saved payment methods';

    public function handle(
        SubscriptionService $subscriptionService,
        PaymentGatewayManager $gatewayManager
    ): int {
        $runId = DB::table('scheduler_runs')->insertGetId([
            'command'    => 'subscriptions:auto-charge',
            'date'       => now()->toDateString(),
            'status'     => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('[AutoCharge] Starting subscription auto-charge run...');
        $charged = 0;
        $failed  = 0;

        try {
            // Fetch unpaid invoices that are linked to active subscriptions
            $dueInvoices = Invoice::query()
                ->where('status', Invoice::STATUS_UNPAID)
                ->whereHas('subscription', function ($q) {
                    $q->where('status', Subscription::STATUS_ACTIVE);
                })
                ->whereDate('created_at', '<=', now())
                ->with(['subscription.entity'])
                ->get();

            $this->info("[AutoCharge] Found {$dueInvoices->count()} due invoice(s) to process.");

            foreach ($dueInvoices as $invoice) {
                try {
                    $this->chargeInvoice($invoice, $gatewayManager, $subscriptionService);
                    $charged++;
                    $this->info("[AutoCharge] Successfully charged invoice #{$invoice->invoice_id}");
                } catch (\Exception $e) {
                    $failed++;
                    $this->error("[AutoCharge] Failed to charge invoice #{$invoice->invoice_id}: " . $e->getMessage());
                    Log::error('[AutoCharge] Failed to charge invoice', [
                        'invoice_id' => $invoice->id,
                        'error'      => $e->getMessage(),
                    ]);

                    // Mark invoice as failed and subscription as Past Due
                    $invoice->update(['status' => Invoice::STATUS_FAILED]);
                    $subscription = $invoice->subscription;
                    if ($subscription) {
                        $subscription->update(['status' => 'Past Due']);
                    }

                    $this->notifyFailure($invoice, $e->getMessage());
                }
            }

            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

            $this->info("[AutoCharge] Completed. Charged: {$charged} | Failed: {$failed}");

        } catch (\Exception $e) {
            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
                'updated_at'    => now(),
            ]);

            $this->error("[AutoCharge] Critical failure: " . $e->getMessage());
            Log::error('[AutoCharge] Critical failure', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Charge a single invoice off-session using the entity's saved Stripe Customer ID
     * and the configured payment provider for the subscription's partner.
     */
    protected function chargeInvoice(
        Invoice $invoice,
        PaymentGatewayManager $gatewayManager,
        SubscriptionService $subscriptionService
    ): void {
        $subscription = $invoice->subscription;
        $entity       = $subscription?->entity;

        if (!$entity) {
            throw new \RuntimeException("Entity not found for invoice #{$invoice->invoice_id}");
        }

        if (!$entity->stripe_customer_id) {
            throw new \RuntimeException("No Stripe Customer ID saved for entity (ID: {$entity->id}). Cannot charge off-session.");
        }

        // Resolve the payment provider and driver for the subscription's partner
        $receiverId      = $subscription->entity_type === \App\Models\Partner\Partner::class
            ? \App\Models\Partner\Partner::where('is_host', true)->first()?->id
            : $invoice->partner_id;

        $partnerProvider = $gatewayManager->resolvePartnerProvider($receiverId);
        $driver          = $gatewayManager->driverFor($partnerProvider);

        $amountInCents = (int) round($invoice->payment_amount * 100);
        $currency      = strtolower($invoice->currency ?? 'usd');
        $description   = "Auto-charge for Invoice #{$invoice->invoice_id}";

        // Off-session charge using the vaulted Stripe Customer ID
        $result = $driver->chargeOffSession(
            $entity->stripe_customer_id,
            $amountInCents,
            $currency,
            $description
        );

        if (empty($result['success'])) {
            throw new \RuntimeException("Stripe off-session charge failed. Status: " . ($result['status'] ?? 'unknown'));
        }

        DB::transaction(function () use ($invoice, $result, $partnerProvider, $subscription, $subscriptionService) {
            // Create payment ledger entry
            $payment = new Payment();
            $payment->createRecord([
                'partner_id'     => $invoice->partner_id,
                'borrower_id'    => $invoice->borrower_id,
                'invoice_id'     => $invoice->id,
                'entity_type'    => $invoice->entity_type,
                'entity_id'      => $invoice->entity_id,
                'transaction_id' => $result['transaction_id'] ?? ('TX_' . uniqid()),
                'amount'         => $invoice->payment_amount,
                'currency'       => strtoupper($invoice->currency ?? 'USD'),
                'payment_from'   => Payment::PAYMENT_FROM_STRIPE,
                'status'         => Payment::STATUS_SUCCEEDED,
                'details'        => $result['raw'] ?? [],
            ]);

            // Mark invoice as paid
            $invoice->update([
                'status'       => Invoice::STATUS_PAID,
                'payment_date' => now(),
            ]);

            // Advance local subscription phase and schedule next billing date
            $subscriptionService->handleSuccessfulPayment($invoice);
        });
    }

    /**
     * Send a failure notification email for a failed auto-charge.
     */
    protected function notifyFailure(Invoice $invoice, string $errorMessage): void
    {
        $emails = explode(',', config('app.cron_notify_emails', ''));
        $emails = array_filter(array_map('trim', $emails));

        if (!empty($emails)) {
            try {
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name'      => 'AutoChargeSubscriptions (subscriptions:auto-charge)',
                    'error_message' => $errorMessage,
                    'timestamp'     => now()->toDateTimeString(),
                    'user_email'    => $invoice->email ?? 'N/A',
                    'user_id'       => $invoice->entity_id ?? 'N/A',
                ]));
            } catch (\Exception $e) {
                Log::error('[AutoCharge] Failed to send failure notification email', ['error' => $e->getMessage()]);
            }
        }
    }
}
