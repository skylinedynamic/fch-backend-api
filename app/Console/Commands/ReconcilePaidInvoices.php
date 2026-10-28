<?php

namespace App\Console\Commands;

use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Settles invoices that were paid but never marked Paid (BUG-005).
 *
 * Older payment code recorded a succeeded Stripe payment and left its invoice
 * Unpaid - INV-20260719001, paid on 19 Jul, was still Unpaid in October and the
 * scheduler kept trying to charge it again. This finds every Unpaid invoice
 * whose succeeded payments add up to its amount and settles it the way a
 * successful payment does: Paid on the day the money came in, and the
 * subscription moved on (SubscriptionService::handleSuccessfulPayment).
 *
 * The payments themselves are not touched. Safe to re-run: a settled invoice is
 * no longer Unpaid. Run with --dry-run first.
 */
class ReconcilePaidInvoices extends Command
{
    protected $signature = 'invoices:reconcile-paid {--dry-run : List the invoices without changing anything}';

    protected $description = 'Mark Paid every Unpaid invoice whose succeeded payments already cover it, and move its subscription on';

    public function handle(SubscriptionService $subscriptionService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $invoices = Invoice::where('status', Invoice::STATUS_UNPAID)
            ->whereHas('payments', fn ($q) => $q->where('status', Payment::STATUS_SUCCEEDED))
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->isCoveredBySucceededPayments())
            ->values();

        if ($invoices->isEmpty()) {
            $this->info('No unpaid invoice is covered by its succeeded payments.');

            return self::SUCCESS;
        }

        $this->table(
            ['Invoice', 'Amount', 'Paid on', 'Subscription', 'Phase', 'Status'],
            $invoices->map(function (Invoice $invoice) {
                $subscription = $invoice->subscription_id ? Subscription::find($invoice->subscription_id) : null;

                return [
                    $invoice->invoice_id ?: $invoice->id,
                    number_format((float) $invoice->payment_amount, 2),
                    $this->paidOn($invoice),
                    $subscription?->id ?? '-',
                    $subscription?->current_phase ?? '-',
                    $subscription?->status ?? '-',
                ];
            })->all()
        );

        if ($dryRun) {
            $this->warn("Dry run: {$invoices->count()} invoice(s) would be marked Paid. Nothing was changed.");

            return self::SUCCESS;
        }

        $settled = 0;

        foreach ($invoices as $invoice) {
            $done = DB::transaction(function () use ($invoice, $subscriptionService) {
                // Re-read under a lock: a payment recorded meanwhile may have
                // settled it already, and settling twice moves the subscription twice.
                $current = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

                if (!$current || $current->status !== Invoice::STATUS_UNPAID || !$current->isCoveredBySucceededPayments()) {
                    return false;
                }

                $current->update([
                    'status' => Invoice::STATUS_PAID,
                    'payment_date' => $this->paidOn($current),
                ]);

                $subscription = $current->subscription_id ? Subscription::find($current->subscription_id) : null;
                $subscriptionService->handleSuccessfulPayment($current->fresh(), $subscription);

                return true;
            });

            $settled += $done ? 1 : 0;
        }

        $this->info("{$settled} invoice(s) marked Paid.");

        return self::SUCCESS;
    }

    /** The day the invoice's latest succeeded payment came in. */
    private function paidOn(Invoice $invoice): string
    {
        $latest = Payment::where('invoice_id', $invoice->id)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->latest('created_at')
            ->value('created_at');

        return $latest ? Carbon::parse($latest)->toDateString() : today()->toDateString();
    }
}
