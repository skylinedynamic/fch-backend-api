<?php

namespace App\Console\Commands;

use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Commission\CommissionPayout;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-off clean-up (decision 2026-11-03, findings F177): a commission belongs
 * only to a Closed (Completed) funding application - it is set while closing.
 * Commissions saved earlier from the old Commission Details card, on
 * applications at any other status (or whose application is gone), are
 * removed here together with what paid them out:
 *
 *   payments   - the payout's payment, and any payment on its invoice (soft-deleted)
 *   invoices   - the payout's invoice, only a commission-payout invoice (soft-deleted)
 *   payouts    - the commission_payouts row (deleted - the table keeps no deleted rows)
 *   commission - soft-deleted
 *
 * Soft-deleted rows leave the app but stay in the database, so they can be restored.
 *
 * Nothing of a Closed (Completed) application, and no other invoice or payment
 * (subscriptions, platform fees), is touched. Each commission is removed in its
 * own transaction: one that fails is left exactly as it was and reported, and
 * a re-run picks up the rest. Run with --dry-run first.
 */
class RemoveUnclosedCommissions extends Command
{
    protected $signature = 'commissions:remove-unclosed
        {--dry-run : Report what would be removed without changing anything}
        {--force : Do not ask for confirmation}';

    protected $description = 'Remove commissions (and their payouts, commission invoices and payments) of funding applications that are not Closed (Completed)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run: nothing will be changed.');
        }

        $commissions = Commission::query()
            ->whereDoesntHave('fundingApplication', fn ($applications) => $applications->where('status', FundingApplication::CLOSED_COMPLETED))
            ->with('fundingApplication:id,status,application_number')
            ->orderBy('id')
            ->get();

        if ($commissions->isEmpty()) {
            $this->info('Every commission belongs to a Closed (Completed) application. Nothing to remove.');

            return self::SUCCESS;
        }

        $plans = $commissions->map(fn (Commission $commission) => $this->planFor($commission));

        $this->table(
            ['Commission', 'Application', 'Status', 'Payouts', 'Invoices', 'Payments'],
            $plans->map(fn (array $plan) => [
                $plan['commission']->id,
                $plan['commission']->fundingApplication?->application_number ?? "#{$plan['commission']->funding_application_id} (gone)",
                $plan['commission']->fundingApplication?->status ?? '-',
                count($plan['payouts']),
                count($plan['invoices']),
                count($plan['payments']),
            ])->all()
        );
        $this->line("Commissions to remove: {$plans->count()}");

        if ($dryRun) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Remove {$plans->count()} commission(s) and their payouts, invoices and payments? Payouts are deleted; commissions, invoices and payments are soft-deleted."
        )) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $removed = ['commissions' => 0, 'payouts' => 0, 'invoices' => 0, 'payments' => 0];
        $failed = 0;

        foreach ($plans as $plan) {
            try {
                DB::transaction(function () use ($plan, &$removed) {
                    // Children first: payouts reference invoices and payments.
                    $payouts = CommissionPayout::whereIn('id', $plan['payouts'])->delete();
                    $payments = Payment::whereIn('id', $plan['payments'])->delete();
                    $invoices = Invoice::whereIn('id', $plan['invoices'])->delete();
                    $plan['commission']->delete();

                    $removed['payouts'] += $payouts;
                    $removed['payments'] += $payments;
                    $removed['invoices'] += $invoices;
                    $removed['commissions']++;
                });

                Log::info('Removed a commission of a funding application that is not Closed (Completed)', [
                    'commission_id' => $plan['commission']->id,
                    'funding_application_id' => $plan['commission']->funding_application_id,
                    'status' => $plan['commission']->fundingApplication?->status,
                    'payout_ids' => $plan['payouts'],
                    'invoice_ids' => $plan['invoices'],
                    'payment_ids' => $plan['payments'],
                ]);
            } catch (Throwable $e) {
                $failed++;
                $this->error("Commission #{$plan['commission']->id} was not removed: {$e->getMessage()}");
                Log::error('Could not remove a commission of a funding application that is not Closed (Completed)', [
                    'commission_id' => $plan['commission']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Removed {$removed['commissions']} commission(s), {$removed['payouts']} payout(s), {$removed['invoices']} invoice(s), {$removed['payments']} payment(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What removing this commission removes: its payouts, their commission-payout
     * invoices, and the payments of those (by the payout's payment_id or the invoice).
     *
     * @return array{commission: Commission, payouts: list<int>, invoices: list<int>, payments: list<int>}
     */
    private function planFor(Commission $commission): array
    {
        $payouts = CommissionPayout::where('commission_id', $commission->id)->get(['id', 'invoice_id', 'payment_id']);

        $invoices = Invoice::whereIn('id', $payouts->pluck('invoice_id')->filter())
            ->where('type', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT)
            ->pluck('id');

        $payments = Payment::query()
            ->where(fn ($query) => $query
                ->whereIn('id', $payouts->pluck('payment_id')->filter())
                ->orWhereIn('invoice_id', $invoices))
            ->pluck('id');

        return [
            'commission' => $commission,
            'payouts' => $payouts->pluck('id')->all(),
            'invoices' => $invoices->all(),
            'payments' => $payments->unique()->values()->all(),
        ];
    }
}
