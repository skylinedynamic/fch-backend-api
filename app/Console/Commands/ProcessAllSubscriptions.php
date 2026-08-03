<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use App\Mail\CronJobFailureMail;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\InvoiceService;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use App\Models\ActivityLog\ActivityLog;

/**
 * Command: subscriptions:process-all
 *
 * Unified subscription lifecycle cron job.
 *
 * Runs in two sequential steps:
 *
 *  STEP 1 — Invoice Generation
 *   a) Partner/Client installment invoices (enrollment phase)
 *   b) Partner/Client platform fee invoices (platform_fee phase)
 *   c) User/Employee seat renewal invoices (subscription_end_date approaching)
 *
 *  STEP 2 — Auto-Charge
 *   - Find all Unpaid invoices linked to active subscriptions
 *   - Attempt off-session charge via saved Stripe Customer ID
 *   - On success: advance subscription phase / extend seat dates
 *   - On failure: mark invoice as Failed, set subscription to Past Due
 */
class ProcessAllSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-all';

    protected $description = 'Unified: generate invoices for all subscription types and auto-charge due invoices.';

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
    ) {
        parent::__construct();
    }

    public function handle(
        SubscriptionService $subscriptionService,
        PaymentGatewayManager $gatewayManager
    ): int {
        $runId = DB::table('scheduler_runs')->insertGetId([
            'command'    => 'subscriptions:process-all',
            'date'       => now()->toDateString(),
            'status'     => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            // ── STEP 1: Generate invoices (all types via unified method) ──────────
            $this->info('=== STEP 1: Generating Invoices ===');
            $subscriptionService->generateDueInvoices();
            $this->info('[Invoices] Done. All due invoices generated.');

            // ── STEP 2: Auto-charge unpaid invoices ─────────────────────────────
            $this->info('=== STEP 2: Auto-Charging Unpaid Invoices ===');
            $this->autoChargeInvoices($subscriptionService, $gatewayManager);

            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

            $this->info('[Done] Unified subscription processing completed.');

        } catch (\Exception $e) {
            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
                'updated_at'    => now(),
            ]);

            Log::error('[ProcessAllSubscriptions] Critical failure: ' . $e->getMessage());
            $this->error('[ProcessAllSubscriptions] Critical failure: ' . $e->getMessage());

            $this->notifyAdmins(
                'ProcessAllSubscriptions (subscriptions:process-all)',
                $e->getMessage(),
                $e->getTraceAsString()
            );

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }


    // ──────────────────────────────────────────────────────────────────────────
    // STEP 2 — Auto-charge all due unpaid invoices
    // ──────────────────────────────────────────────────────────────────────────

    protected function autoChargeInvoices(
        SubscriptionService $subscriptionService,
        PaymentGatewayManager $gatewayManager
    ): void {
        $dueInvoices = Invoice::query()
            ->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('subscription', fn($q) => $q->where('status', Subscription::STATUS_ACTIVE))
            ->whereDate('created_at', '<=', now())
            ->with(['subscription.entity'])
            ->get();

        $this->info("[AutoCharge] Found {$dueInvoices->count()} unpaid invoice(s) to charge.");

        $charged = 0;
        $failed  = 0;

        foreach ($dueInvoices as $invoice) {
            try {
                $this->chargeInvoice($invoice, $gatewayManager, $subscriptionService);
                $charged++;
                $this->info("[AutoCharge] Charged invoice #{$invoice->invoice_id}");
            } catch (\Exception $e) {
                $failed++;
                Log::error('[AutoCharge] Failed to charge invoice', [
                    'invoice_id' => $invoice->id,
                    'error'      => $e->getMessage(),
                ]);
                $this->error("[AutoCharge] Failed invoice #{$invoice->invoice_id}: " . $e->getMessage());

                $invoice->update(['status' => Invoice::STATUS_FAILED]);

                if ($invoice->subscription) {
                    $invoice->subscription->update(['status' => 'Past Due']);
                }

                $this->notifyAdmins(
                    'ProcessAllSubscriptions — Auto-Charge',
                    $e->getMessage(),
                    '',
                    ['invoice_id' => $invoice->id, 'user_email' => $invoice->email ?? 'N/A']
                );
            }
        }

        $this->info("[AutoCharge] Completed. Charged: {$charged} | Failed: {$failed}");
    }

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
            throw new \RuntimeException("No Stripe Customer ID for entity ID {$entity->id} — cannot auto-charge.");
        }

        // For Partner/User seat invoices the receiver is the Host; for Borrowers it is the partner
        $receiverId = ($subscription->entity_type === Partner::class)
            ? Partner::where('is_host', true)->first()?->id
            : $invoice->partner_id;

        $partnerProvider = $gatewayManager->resolvePartnerProvider($receiverId);
        $driver          = $gatewayManager->driverFor($partnerProvider);

        $result = $driver->chargeOffSession(
            $entity->stripe_customer_id,
            (int) round($invoice->payment_amount * 100),
            strtolower($invoice->currency ?? 'usd'),
            "Auto-charge for Invoice #{$invoice->invoice_id}"
        );

        if (empty($result['success'])) {
            throw new \RuntimeException("Stripe charge failed. Status: " . ($result['status'] ?? 'unknown'));
        }

        DB::transaction(function () use ($invoice, $result, $subscriptionService) {
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

            $invoice->update([
                'status'       => Invoice::STATUS_PAID,
                'payment_date' => now(),
            ]);

            // Advance subscription phase and/or extend seat dates
            $subscriptionService->handleSuccessfulPayment($invoice);
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ──────────────────────────────────────────────────────────────────────────

    protected function notifyAdmins(string $jobName, string $errorMessage, string $trace = '', array $extra = []): void
    {
        $emails = array_filter(array_map('trim', explode(',', config('app.cron_notify_emails', ''))));

        if (empty($emails)) {
            return;
        }

        try {
            Mail::to($emails)->send(new CronJobFailureMail(array_merge([
                'job_name'      => $jobName,
                'error_message' => $errorMessage,
                'timestamp'     => now()->toDateTimeString(),
                'stack_trace'   => $trace,
            ], $extra)));
        } catch (\Exception $e) {
            Log::error('[ProcessAllSubscriptions] Failed to send admin notification', ['error' => $e->getMessage()]);
        }
    }
}
