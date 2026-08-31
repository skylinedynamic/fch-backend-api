<?php

namespace App\Console\Commands;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Mail\CronJobFailureMail;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
 *   c) User/Employee seat renewal invoices (expected_end_date approaching)
 *
 *  STEP 2 — Auto-Charge
 *   - Query all Unpaid invoices linked to active subscriptions
 *   - Dispatch ChargeSubscriptionInvoiceJob to database queue for background off-session charging
 */
class ProcessAllSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-all';

    protected $description = 'Unified: generate invoices for all subscription types and dispatch auto-charge jobs to database queue.';

    public function __construct(
        protected InvoiceService $invoiceService,
        protected ActivityLogService $activityLogService,
    ) {
        parent::__construct();
        $this->description = __('subscription.command_description');
    }

    public function handle(SubscriptionService $subscriptionService): int
    {
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
            $this->info(__('subscription.step_1_generating_invoices'));
            $subscriptionService->generateDueInvoices();
            $this->info(__('subscription.invoices_generated'));

            // ── STEP 2: Dispatch auto-charge jobs to database queue ─────────────
            $this->info(__('subscription.step_2_dispatching_jobs'));
            $this->dispatchAutoChargeJobs();

            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

            $this->info(__('subscription.processing_completed'));

        } catch (\Exception $e) {
            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status'        => 'failed',
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
                'updated_at'    => now(),
            ]);

            $failureMessage = __('subscription.critical_failure') . $e->getMessage();
            Log::error($failureMessage);
            $this->error($failureMessage);

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
    // STEP 2 — Dispatch auto-charge jobs to Redis queue
    // ──────────────────────────────────────────────────────────────────────────

    protected function dispatchAutoChargeJobs(): void
    {
        $dueInvoices = Invoice::query()
            ->where('status', Invoice::STATUS_UNPAID)
            ->where('payment_amount', '>', 0)
            ->whereHas('subscription', function ($q) {
                // billing_paused_at means the subscriber is not Active, so Step 1
                // has stopped raising new cycles for them. Taking money off-session
                // from a suspended customer's card while their service is switched
                // off is what produces chargebacks, so collection pauses with it.
                // The invoice stays Unpaid and payable — the debt is not forgiven,
                // it just is not auto-charged until they are Active again.
                $q->where('status', Subscription::STATUS_ACTIVE)
                    ->whereNull('billing_paused_at');
            })
            ->whereDate('created_at', '<=', now())
            ->get();

        $this->info(__('subscription.found_unpaid_invoices', ['count' => $dueInvoices->count()]));

        foreach ($dueInvoices as $invoice) {
            ChargeSubscriptionInvoiceJob::dispatch($invoice);
        }

        $this->info(__('subscription.dispatched_charge_jobs', ['count' => $dueInvoices->count()]));
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
            Log::error(__('subscription.failed_send_admin_notify'), ['error' => $e->getMessage()]);
        }
    }
}
