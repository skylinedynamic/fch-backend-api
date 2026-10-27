<?php

namespace App\Jobs;

use App\Services\FCH\Common\Notification\Notifier;
use App\Mail\CronJobFailureMail;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use App\Services\GlobalPayment\PaymentMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\CardException;

class ChargeSubscriptionInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 2;

    /**
     * Marks a failure the payer has to act on, rather than one worth retrying.
     *
     * Recorded on the ledger entry so a later pass can tell it has already asked
     * them once - this job runs twice a day and would otherwise send the same
     * email until the invoice was dealt with.
     */
    public const REASON_CARD_ACCOUNT_MISMATCH = 'card_account_mismatch';

    /**
     * Set on a failure's ledger entry once the payer has been told their
     * automatic payment did not go through (PaymentFailedMail) - once per
     * invoice, for the same reason as above.
     */
    public const DETAIL_PAYER_TOLD_OF_FAILURE = 'payer_told_of_failure';

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public int $backoff = 60;

    /**
     * How long the "already queued" lock may outlive a job that never released
     * it (a worker killed mid-run). Kept under the 12-hour gap between cron
     * passes, so a stuck lock costs at most one pass rather than blocking the
     * invoice for good.
     */
    public int $uniqueFor = 11 * 60 * 60;

    /**
     * Create a new job instance.
     *
     * Deliberately NOT pinned to a connection. It used to be pinned to
     * `database`, but production workers run on the default (Redis) connection,
     * so nothing ever picked these up - invoices sat in the jobs table and were
     * never auto-charged.
     */
    public function __construct(public Invoice $invoice)
    {
    }

    /**
     * One queued charge per invoice. The cron dispatches every Unpaid invoice
     * twice a day; without this, each pass adds another copy while the last is
     * still waiting.
     */
    public function uniqueId(): string
    {
        return (string) $this->invoice->id;
    }

    /**
     * Execute the job.
     */
    /**
     * Why this payer must not be charged, or null if they may be.
     *
     * The payer is whoever's card is about to be debited — a Partner for partner
     * fees, seats and borrower fees, or a Borrower paying their own enrollment. A
     * borrower is also blocked when their partner is not Active, since the borrower
     * reaches the platform through that partner.
     *
     * @param  mixed $payer
     * @return string|null
     */
    protected function payerNotChargeable($payer): ?string
    {
        if ($payer instanceof Partner) {
            return $payer->status !== Partner::STATUS_ACTIVE
                ? 'Partner status is ' . $payer->status
                : null;
        }

        if ($payer instanceof Borrower) {
            if ($payer->status !== Borrower::STATUS_ACTIVE) {
                return 'Borrower status is ' . $payer->status;
            }

            $payer->loadMissing('partner');

            if (!$payer->partner) {
                return 'Borrower has no partner';
            }

            return $payer->partner->status !== Partner::STATUS_ACTIVE
                ? "Borrower's partner status is " . $payer->partner->status
                : null;
        }

        return null;
    }

    public function handle(
        PaymentGatewayManager $gatewayManager,
        SubscriptionService $subscriptionService
    ): void {
        $invoice = $this->invoice->fresh(['subscription.entity']);

        if (!$invoice || $invoice->status !== Invoice::STATUS_UNPAID) {
            return;
        }

        // Auto-charge has stopped for this invoice (BUG-005): it is paid by hand
        // from now on. The dispatcher skips these; a job queued earlier or by
        // hand ends here.
        if ($invoice->auto_charge_stopped_at) {
            return;
        }

        // Paid already, whatever the status says: older code recorded a Stripe
        // payment and left the invoice Unpaid, and this job kept charging it
        // twice a day. Stripe forgets an idempotency key after 24 hours, so a
        // working card would have paid twice. Stop, and have it reconciled.
        if ($invoice->isCoveredBySucceededPayments()) {
            if ($this->stopAutoCharge($invoice, Invoice::AUTO_CHARGE_STOP_ALREADY_PAID)) {
                $this->notifyAdmins(__('payment.auto_charge_stopped.already_paid_alert', [
                    'invoice' => $invoice->invoice_id ?: $invoice->id,
                    'amount' => number_format((float) $invoice->payment_amount, 2),
                ]));
            }

            return;
        }

        $subscription = $invoice->subscription;

        // Never take money off-session while billing is paused. The dispatcher
        // already filters these out, but this is the point where money actually
        // moves, so the guard belongs here too — it also covers a job dispatched by
        // hand or one queued before the subscriber was suspended.
        //
        // The invoice is left Unpaid rather than failed: the debt stands and can
        // still be settled manually, it just is not auto-charged while the
        // subscriber's service is switched off.
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

        // Never charge a card belonging to someone who is not Active.
        //
        // This checks the PAYER directly, not the subscription. Step 1 never sees a
        // subscription whose subscription_end_date is NULL (a finished borrower, or
        // one still awaiting a first payment), yet such a row can still carry an
        // unpaid invoice — a borrower fee, say — and without this guard it would be
        // auto-charged twice a day while the account is suspended.
        //
        // Left Unpaid rather than failed: the debt stands and can still be settled
        // manually once they are Active again.
        if ($blockedReason = $this->payerNotChargeable($payer)) {
            Log::info('Skipped auto-charge: payer is not Active', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscription?->id,
                'payer' => class_basename($payer) . '#' . $payer->id,
                'reason' => $blockedReason,
            ]);
            return;
        }

        if (empty($payer->stripe_customer_id_raw)) {
            $entityName = class_basename($payer);
            // Never saved, or removed by the payer (F110): only they can pay it now.
            $this->failAndTellPayer("No saved Stripe Customer ID for {$entityName} ID {$payer->id}", [], noSavedCard: true);
            return;
        }

        // 2. Resolve Receiver Partner
        $receiverId = (in_array($invoice->entity_type, [Partner::class, User::class, Employee::class]) || $invoice->is_partner_paying || in_array($subscription?->entity_type, [Partner::class, User::class, Employee::class]))
            ? Partner::where('is_host', true)->value('id')
            : $invoice->partner_id;

        $partnerProvider = $gatewayManager->resolvePartnerProvider($receiverId);

        // A saved card lives in one Stripe account. Charging it from the other
        // fails at Stripe with a message about the token rather than the mode,
        // so refuse here and say which mismatch it was.
        $storedMode = is_array($payer->stripe_customer_id)
            ? ($payer->stripe_customer_id['payment_mode'] ?? null)
            : null;
        $activeMode = $partnerProvider->paymentMode();

        if ($storedMode && $storedMode !== $activeMode) {
            // Ask once. Every later pass still records the failure, but sending
            // the same email twice a day until the invoice is settled would
            // train the payer to ignore it.
            $alreadyAsked = Payment::where('invoice_id', $invoice->id)
                ->where('status', Payment::STATUS_FAILED)
                ->get()
                ->contains(fn ($failure) => ($failure->details['reason_code'] ?? null) === self::REASON_CARD_ACCOUNT_MISMATCH);

            $this->markFailed(
                "Saved card belongs to the {$storedMode} Stripe account but the receiving partner is in {$activeMode} mode; the payer must re-enter their card.",
                ['reason_code' => self::REASON_CARD_ACCOUNT_MISMATCH]
            );

            if (!$alreadyAsked) {
                app(PaymentMailService::class)->sendCardReauthorizationRequired($invoice);
            }

            return;
        }

        $driver = $gatewayManager->driverFor($partnerProvider);

        $currency = strtolower($invoice->currency ?? Invoice::CURRENCY_USD);
        $amountInCents = (int) round($invoice->payment_amount * 100);
        $description = "Auto-charge for Invoice #{$invoice->invoice_id}";

        try {
            $result = $driver->chargeOffSession(
                $payer->stripe_customer_id_raw,
                $amountInCents,
                $currency,
                $description,
                // Names the debt, so this job retrying - or the next cron pass
                // finding the invoice still Unpaid after a charge that succeeded
                // but was not recorded - replays the original charge instead of
                // taking the money a second time.
                'invoice_' . $invoice->id
            );

            if (empty($result['success'])) {
                $rawDetails = is_array($result) ? ($result['raw'] ?? $result) : [];

                // Worth distinguishing even here, where nobody is watching: a card
                // needing bank authentication can never be charged off-session, so
                // it will fail on every pass until the payer replaces it. A
                // "declined" line would have this retried forever as if the next
                // attempt might work.
                $reason = !empty($result['requires_action'])
                    ? 'Saved card needs bank authentication (3-D Secure), which cannot be completed off-session; the payer must use a different card.'
                    : 'Stripe off-session charge declined: ' . ($result['status'] ?? 'unknown');

                $this->failAndTellPayer($reason, $rawDetails);
                return;
            }

            $recorded = DB::transaction(function () use ($invoice, $result, $subscriptionService) {
                // Re-read under a row lock. A duplicate of this job that got here
                // first was handed the same charge by Stripe (same idempotency
                // key), so if it has already marked the invoice Paid there is
                // nothing left to record - doing so again would log a second
                // payment and advance the subscription twice.
                $current = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

                if (!$current || $current->status !== Invoice::STATUS_UNPAID) {
                    return false;
                }

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
                    'payment_from' => PaymentProvider::STRIPE,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'details' => $result['raw'] ?? [],
                ]);

                $invoice->update([
                    'status' => Invoice::STATUS_PAID,
                    // payment_date is a DATE column - store the calendar date.
                    'payment_date' => today(),
                ]);

                $subscription = Subscription::find($invoice->subscription_id);

                // Advance subscription phase and/or extend renewal dates
                $subscriptionService->handleSuccessfulPayment($invoice, $subscription);

                return true;
            });

            if (!$recorded) {
                Log::info("[Queue AutoCharge] Invoice #{$invoice->invoice_id} was already settled by another run; nothing recorded");
                return;
            }

            Log::info("[Queue AutoCharge] Successfully charged invoice #{$invoice->invoice_id}");
        } catch (CardException $e) {
            // Stripe declined the card (a 402) - the payer's to sort out.
            $this->failAndTellPayer('Stripe off-session charge declined: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'decline_code' => $e->getDeclineCode(),
            ]);
        } catch (\Exception $e) {
            // A gateway or system error is the admins' to fix (markFailed alerts them).
            $this->markFailed($e->getMessage(), ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Record a failure the payer can act on - a declined card, or no saved card
     * to charge - and tell them, once per invoice (findings O46). The admins'
     * alert in markFailed() still goes out as before.
     */
    protected function failAndTellPayer(string $reason, array $details = [], bool $noSavedCard = false): void
    {
        $alreadyTold = Payment::where('invoice_id', $this->invoice->id)
            ->where('status', Payment::STATUS_FAILED)
            ->get()
            ->contains(fn ($failure) => (bool) ($failure->details[self::DETAIL_PAYER_TOLD_OF_FAILURE] ?? false));

        $this->markFailed($reason, $details + [self::DETAIL_PAYER_TOLD_OF_FAILURE => true]);

        if (!$alreadyTold) {
            app(PaymentMailService::class)->sendPaymentFailed($this->invoice, $noSavedCard);
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
                'payment_from' => PaymentProvider::STRIPE,
                'status' => Payment::STATUS_FAILED,
                'details' => array_merge(['error' => $reason], $details),
            ]);
        } catch (\Exception $e) {
            Log::error('[Queue AutoCharge] Failed to create failed payment record', ['error' => $e->getMessage()]);
        }

        // Deciding whether this subscription is now Past Due belongs solely to
        // SubscriptionService::createInvoiceIfDue(), which runs on the very next
        // cron pass (Step 1, before this job's queue is even re-dispatched in
        // Step 2). This job used to duplicate that check here against
        // subscription_end_date — but that field now means "paid through", which
        // is in the past almost immediately after any payment, so it would have
        // marked subscriptions Past Due on the first failed charge attempt,
        // regardless of grace period. Removed rather than fixed to avoid two
        // independent implementations of the same decision drifting again.

        Log::error("[Queue AutoCharge] Failed auto-charge for invoice #{$this->invoice->invoice_id}: {$reason}");

        $this->notifyAdmins($reason);

        $this->stopAfterTooManyFailures();
    }

    /**
     * After config('subscription.auto_charge_max_failed_attempts') failures the
     * invoice is no longer auto-charged (BUG-005) - the cron used to retry it
     * twice a day forever. The payer, and a client's partner, are told once;
     * the invoice stays Unpaid and payable by hand.
     */
    protected function stopAfterTooManyFailures(): void
    {
        // Only the cron's own attempts: declined "Pay Now" attempts are the payer
        // trying by hand and do not use up the six (BUG-035).
        $failures = Payment::where('invoice_id', $this->invoice->id)
            ->where('status', Payment::STATUS_FAILED)
            ->get()
            ->reject(fn ($failure) => ($failure->details[Payment::DETAIL_FAILURE_SOURCE] ?? null) === Payment::FAILURE_SOURCE_CHECKOUT)
            ->count();

        if ($failures < (int) config('subscription.auto_charge_max_failed_attempts', 6)) {
            return;
        }

        if ($this->stopAutoCharge($this->invoice, Invoice::AUTO_CHARGE_STOP_TOO_MANY_FAILURES)) {
            app(PaymentMailService::class)->sendAutoChargeStopped($this->invoice, $failures);
        }
    }

    /**
     * Stop auto-charging the invoice. True only for the call that stopped it,
     * so whoever is told about it is told once.
     */
    protected function stopAutoCharge(Invoice $invoice, string $reason): bool
    {
        return Invoice::whereKey($invoice->id)
            ->whereNull('auto_charge_stopped_at')
            ->update([
                'auto_charge_stopped_at' => now(),
                'auto_charge_stop_reason' => $reason,
            ]) === 1;
    }

    /** The ops mailbox (CRON_NOTIFY_EMAILS). */
    protected function notifyAdmins(string $reason): void
    {
        $emails = array_filter(array_map('trim', explode(',', config('app.cron_notify_emails', ''))));

        if (!empty($emails)) {
            try {
                app(Notifier::class)->send('system.cron_failed', mail: (new CronJobFailureMail([
                    'job_name' => 'ChargeSubscriptionInvoiceJob',
                    'error_message' => $reason,
                    'timestamp' => now()->toDateTimeString(),
                    'user_email' => $this->invoice->email ?? 'N/A',
                    'user_id' => $this->invoice->entity_id ?? 'N/A',
                ]))->to($emails));
            } catch (\Exception $e) {
                Log::error('[Queue AutoCharge] Failed to send failure notification email', ['error' => $e->getMessage()]);
            }
        }
    }
}
