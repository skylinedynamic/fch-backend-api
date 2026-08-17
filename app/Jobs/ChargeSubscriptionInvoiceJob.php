<?php

namespace App\Jobs;

use App\Mail\CronJobFailureMail;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChargeSubscriptionInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public Invoice $invoice)
    {
        $this->onConnection('database');
    }

    /**
     * Execute the job.
     */
    public function handle(
        PaymentGatewayManager $gatewayManager,
        SubscriptionService $subscriptionService
    ): void {
        $invoice = $this->invoice->fresh(['subscription.entity']);

        if (!$invoice || $invoice->status !== Invoice::STATUS_UNPAID) {
            return;
        }

        $subscription = $invoice->subscription;

        // 1. Resolve Payer Entity
        $payer = null;
        if ($invoice->is_partner_paying || in_array($invoice->entity_type, [User::class, Employee::class]) || in_array($subscription?->entity_type, [User::class, Employee::class])) {
            $payer = Partner::find($invoice->partner_id);
        } elseif ($invoice->entity_type === Partner::class) {
            $payer = Partner::find($invoice->entity_id);
        } elseif ($invoice->entity_type === Borrower::class) {
            $payer = Borrower::find($invoice->entity_id);
        } else {
            $payer = $subscription?->entity;
        }

        if (!$payer) {
            $this->markFailed("Payer entity not found for invoice #{$invoice->invoice_id}");
            return;
        }

        if (empty($payer->stripe_customer_id_raw)) {
            $entityName = class_basename($payer);
            $this->markFailed("No saved Stripe Customer ID for {$entityName} ID {$payer->id}");
            return;
        }

        // 2. Resolve Receiver Partner
        $receiverId = (in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]) || $invoice->is_partner_paying || in_array($subscription?->entity_type, [Partner::class, User::class, Employee::class]))
            ? Partner::where('is_host', true)->value('id')
            : $invoice->partner_id;

        $partnerProvider = $gatewayManager->resolvePartnerProvider($receiverId);
        $driver = $gatewayManager->driverFor($partnerProvider);

        $currency = strtolower($invoice->currency ?? Invoice::CURRENCY_USD);
        $amountInCents = (int) round($invoice->payment_amount * 100);
        $description = "Auto-charge for Invoice #{$invoice->invoice_id}";

        try {
            $result = $driver->chargeOffSession(
                $payer->stripe_customer_id_raw,
                $amountInCents,
                $currency,
                $description
            );

            if (empty($result['success'])) {
                $rawDetails = is_array($result) ? ($result['raw'] ?? $result) : [];
                $this->markFailed("Stripe off-session charge declined: " . ($result['status'] ?? 'unknown'), $rawDetails);
                return;
            }

            DB::transaction(function () use ($invoice, $result, $subscriptionService) {
                $payment = new Payment();
                $payment->createRecord([
                    'partner_id' => $invoice->partner_id,
                    'borrower_id' => $invoice->borrower_id,
                    'invoice_id' => $invoice->id,
                    'entity_type' => $invoice->entity_type,
                    'entity_id' => $invoice->entity_id,
                    'transaction_id' => $result['transaction_id'] ?? ('TX_' . uniqid()),
                    'amount' => $invoice->payment_amount,
                    'currency' => strtoupper($invoice->currency ?? Payment::CURRENCY_USD),
                    'payment_from' => Payment::PAYMENT_FROM_STRIPE,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'details' => $result['raw'] ?? [],
                ]);

                $invoice->update([
                    'status' => Invoice::STATUS_PAID,
                    'payment_date' => now(),
                ]);

                $subscription = Subscription::find($invoice->subscription_id);

                // Advance subscription phase and/or extend renewal dates
                $subscriptionService->handleSuccessfulPayment($invoice, $subscription);
            });

            Log::info("[Queue AutoCharge] Successfully charged invoice #{$invoice->invoice_id}");
        } catch (\Exception $e) {
            $this->markFailed($e->getMessage(), ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Handle charge failure: keeps invoice Unpaid, inserts failed payment ledger entry, and sends alert.
     */
    protected function markFailed(string $reason, array $details = []): void
    {
        // 1. Keep invoice status Unpaid, but insert a failed payment ledger entry
        try {
            $payment = new Payment();
            $payment->createRecord([
                'partner_id' => $this->invoice->partner_id,
                'borrower_id' => $this->invoice->borrower_id,
                'invoice_id' => $this->invoice->id,
                'entity_type' => $this->invoice->entity_type,
                'entity_id' => $this->invoice->entity_id,
                'transaction_id' => 'FAILED_' . uniqid(),
                'amount' => $this->invoice->payment_amount,
                'currency' => strtoupper($this->invoice->currency ?? Payment::CURRENCY_USD),
                'payment_from' => Payment::PAYMENT_FROM_STRIPE,
                'status' => Payment::STATUS_FAILED,
                'details' => array_merge(['error' => $reason], $details),
            ]);
        } catch (\Exception $e) {
            Log::error('[Queue AutoCharge] Failed to create failed payment record', ['error' => $e->getMessage()]);
        }

        if ($this->invoice->subscription) {
            $this->invoice->subscription->update(['status' => Subscription::STATUS_PAST_DUE]);
        }

        Log::error("[Queue AutoCharge] Failed auto-charge for invoice #{$this->invoice->invoice_id}: {$reason}");

        $emails = array_filter(array_map('trim', explode(',', config('app.cron_notify_emails', ''))));

        if (!empty($emails)) {
            try {
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name' => 'ChargeSubscriptionInvoiceJob (Database Queue)',
                    'error_message' => $reason,
                    'timestamp' => now()->toDateTimeString(),
                    'user_email' => $this->invoice->email ?? 'N/A',
                    'user_id' => $this->invoice->entity_id ?? 'N/A',
                ]));
            } catch (\Exception $e) {
                Log::error('[Queue AutoCharge] Failed to send failure notification email', ['error' => $e->getMessage()]);
            }
        }
    }
}
