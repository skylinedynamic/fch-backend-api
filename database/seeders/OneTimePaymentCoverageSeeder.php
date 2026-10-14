<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Commission\CommissionPayout;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-off: move existing One Time Payment subscriptions onto the 12-month rule.
 *
 * A partner's One Time Payment used to cover one month before the platform fee
 * started; it now covers Subscription::ONE_TIME_PAYMENT_COVERAGE_MONTHS from the
 * day it was paid (see SubscriptionService::handleOneTimePayment()).
 *
 * ---------------------------------------------------------------------------
 * HOW TO RUN
 * ---------------------------------------------------------------------------
 *   Dry run (DEFAULT - prints every change, writes nothing):
 *     php artisan db:seed --class=OneTimePaymentCoverageSeeder
 *
 *   Apply for real (PowerShell):
 *     $env:SEED_DRY_RUN="false"; php artisan db:seed --class=OneTimePaymentCoverageSeeder
 *
 *   Apply for real (bash):
 *     SEED_DRY_RUN=false php artisan db:seed --class=OneTimePaymentCoverageSeeder
 *
 * Back the database up first: platform-fee invoices and payments are FORCE
 * deleted, which cannot be undone.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES
 * ---------------------------------------------------------------------------
 * Only One Time Payment subscriptions whose enrollment invoice is Paid. Pending
 * and Cancelled subscriptions are skipped.
 *
 * Partner:
 *   subscription_end_date    enrollment payment_date + 12 months - but never moved
 *                            EARLIER, so platform fees already paid past the 12
 *                            months (or a second run) keep their date
 *   current_phase            platform_fee
 *   status                   Active
 *   installments_paid        1
 *   enrollment_completed_at  kept, else the payment date
 *   current_cycle_invoice_id NULL
 *
 *   Platform-fee invoices raised before (end date - generate_before_days) - the
 *   months the one-time payment now covers - are force deleted together with their
 *   payments, whatever their status, except Pending. One raised on or after that
 *   point is the legitimate first fee after the 12 months and is kept.
 *
 *   Commission payouts that pointed at a deleted invoice/payment are left as they
 *   are; the foreign keys null themselves. They are listed in the report.
 *
 * Borrower:
 *   subscription_end_date NULL, current_phase completed, status Active,
 *   current_cycle_invoice_id NULL.
 */
class OneTimePaymentCoverageSeeder extends Seeder
{
    /** Null reads SEED_DRY_RUN, which defaults to a dry run. */
    public ?bool $dryRun = null;

    protected int $subscriptionsChanged = 0;
    protected int $invoicesDeleted = 0;
    protected int $paymentsDeleted = 0;

    /** @var array<int,string> */
    protected array $manualReview = [];

    /** @var array<int,string> */
    protected array $orphanedPayouts = [];

    public function run(): void
    {
        $this->dryRun ??= filter_var(env('SEED_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN);

        $this->line('');
        $this->line('==============================================================');
        $this->line(' One Time Payment -> ' . Subscription::ONE_TIME_PAYMENT_COVERAGE_MONTHS . '-month coverage');
        $this->line(' MODE: ' . ($this->dryRun ? 'DRY RUN (no writes)' : '*** APPLYING CHANGES ***'));
        $this->line('==============================================================');

        DB::beginTransaction();

        try {
            $subscriptions = Subscription::query()
                ->where('enrollment_type', SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT)
                ->whereIn('entity_type', [Partner::class, Borrower::class])
                ->whereNotIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_CANCELLED])
                ->orderBy('id')
                ->get();

            $this->line(sprintf(' %d subscription(s) to inspect', $subscriptions->count()));

            foreach ($subscriptions as $subscription) {
                $this->repair($subscription);
            }

            $this->summary();

            // A dry run always rolls back, so nothing slips through a missed guard.
            $this->dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function repair(Subscription $subscription): void
    {
        $enrollment = Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_ENROLLMENT)
            ->where('status', Invoice::STATUS_PAID)
            ->orderBy('id')
            ->first();

        if (!$enrollment) {
            $this->flagManual($subscription, 'no Paid enrollment invoice - skipped');
            return;
        }

        $paidOn = $enrollment->payment_date ?? $subscription->first_payment_date;

        if (!$paidOn) {
            $this->flagManual($subscription, 'enrollment invoice #' . $enrollment->id
                . ' has no payment_date and the subscription no first_payment_date - skipped');
            return;
        }

        $paidOn = Carbon::parse($paidOn)->startOfDay();
        $isBorrower = $subscription->entity_type === Borrower::class;

        $this->line('');
        $this->line(sprintf(
            ' subscription #%-4d %-8s #%-4s  paid %s',
            $subscription->id,
            class_basename($subscription->entity_type),
            $subscription->entity_id,
            $paidOn->toDateString()
        ));

        $changes = $isBorrower
            ? $this->borrowerChanges()
            : $this->partnerChanges($subscription, $paidOn);

        if (!$isBorrower) {
            $this->removeCoveredPlatformFees($subscription, $changes['subscription_end_date']);
        }

        $this->apply($subscription, $changes);
    }

    protected function borrowerChanges(): array
    {
        return [
            'subscription_end_date' => null,
            'current_phase' => Subscription::PHASE_COMPLETED,
            'status' => Subscription::STATUS_ACTIVE,
            'current_cycle_invoice_id' => null,
        ];
    }

    protected function partnerChanges(Subscription $subscription, Carbon $paidOn): array
    {
        $coverageEnd = $paidOn->copy()->addMonths(Subscription::ONE_TIME_PAYMENT_COVERAGE_MONTHS);

        // Never move a date earlier: one already past the coverage end was bought by
        // platform fees paid after it, or set by an earlier run of this seeder.
        $current = $subscription->subscription_end_date?->copy()->startOfDay();
        $endDate = $current && $current->gt($coverageEnd) ? $current : $coverageEnd;

        return [
            'subscription_end_date' => $endDate,
            'current_phase' => Subscription::PHASE_PLATFORM_FEE,
            'status' => Subscription::STATUS_ACTIVE,
            'installments_paid' => 1,
            'enrollment_completed_at' => $subscription->enrollment_completed_at ?: $paidOn,
            'current_cycle_invoice_id' => null,
        ];
    }

    /**
     * Force delete the platform fees the one-time payment now covers, with their
     * payments. The cron raises the first fee after the coverage generate_before_days
     * ahead of the end date, so anything raised before that point is covered.
     */
    protected function removeCoveredPlatformFees(Subscription $subscription, Carbon $endDate): void
    {
        $firstLegitimate = $endDate->copy()
            ->subDays((int) config('subscription.generate_before_days', 5));

        $invoices = Invoice::withTrashed()
            ->where('subscription_id', $subscription->id)
            ->where(function ($q) {
                $q->where('type', Invoice::INVOICE_TYPE_PLATFORM_FEE)->orWhere('is_platform_fee', 1);
            })
            ->where('status', '!=', Invoice::STATUS_PENDING)
            ->where('created_at', '<', $firstLegitimate)
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $payments = Payment::withTrashed()->where('invoice_id', $invoice->id)->get();

            $this->line(sprintf(
                '     DELETE invoice #%-5d %-8s %10s  raised %s  (%d payment(s))',
                $invoice->id,
                $invoice->status,
                $invoice->payment_amount,
                Carbon::parse($invoice->created_at)->toDateString(),
                $payments->count()
            ));

            $this->recordOrphanedPayouts($invoice, $payments);

            if (!$this->dryRun) {
                foreach ($payments as $payment) {
                    $payment->forceDelete();
                }

                $invoice->forceDelete();
            }

            $this->paymentsDeleted += $payments->count();
            $this->invoicesDeleted++;
        }
    }

    protected function recordOrphanedPayouts(Invoice $invoice, $payments): void
    {
        $payouts = CommissionPayout::query()
            ->where('invoice_id', $invoice->id)
            ->when($payments->isNotEmpty(), fn($q) => $q->orWhereIn('payment_id', $payments->pluck('id')))
            ->pluck('id');

        foreach ($payouts as $payoutId) {
            $this->orphanedPayouts[] = sprintf(
                ' commission payout #%-5d loses its link to invoice #%d - left as it is',
                $payoutId,
                $invoice->id
            );
        }
    }

    protected function apply(Subscription $subscription, array $changes): void
    {
        $diff = $this->diff($subscription, $changes);

        if (!$diff) {
            $this->line('     already correct');
            return;
        }

        foreach ($diff as $column => [$from, $to]) {
            $this->line(sprintf('     %-26s %-12s -> %s', $column, $this->fmt($from), $this->fmt($to)));
        }

        if (!$this->dryRun) {
            $subscription->update($changes);
            $this->refreshUsersOf($subscription);
        }

        $this->subscriptionsChanged++;
    }

    /** The portals cache billing dates in their auth store; make them re-fetch. */
    protected function refreshUsersOf(Subscription $subscription): void
    {
        User::where('entity_type', $subscription->entity_type)
            ->where('entity_id', $subscription->entity_id)
            ->get()
            ->each(fn(User $user) => $user->markAsRefresh());
    }

    // ------------------------------------------------------------------
    // Reporting helpers
    // ------------------------------------------------------------------

    /** Columns whose value would actually change, as column => [from, to]. */
    protected function diff($model, array $changes): array
    {
        $diff = [];

        foreach ($changes as $column => $new) {
            $old = $model->getAttribute($column);

            if ($this->normalise($old) !== $this->normalise($new)) {
                $diff[$column] = [$old, $new];
            }
        }

        return $diff;
    }

    protected function normalise($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->toDateString();
        }

        if (is_numeric($value)) {
            return (string) round((float) $value, 2);
        }

        return (string) $value;
    }

    protected function fmt($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->toDateString();
        }

        return (string) $value;
    }

    protected function flagManual(Subscription $subscription, string $reason): void
    {
        $this->manualReview[] = sprintf(' subscription #%-4d %s', $subscription->id, $reason);
    }

    protected function summary(): void
    {
        foreach ([
            'NEEDS MANUAL REVIEW' => $this->manualReview,
            'COMMISSION PAYOUTS LEFT UNLINKED' => $this->orphanedPayouts,
        ] as $title => $rows) {
            if (!$rows) {
                continue;
            }

            $this->line('');
            $this->line('--------------------------------------------------------------');
            $this->line(' ' . $title);
            $this->line('--------------------------------------------------------------');
            foreach ($rows as $row) {
                $this->line($row);
            }
        }

        $this->line('');
        $this->line('--------------------------------------------------------------');
        $this->line(sprintf(' subscriptions changed : %d', $this->subscriptionsChanged));
        $this->line(sprintf(' invoices deleted      : %d (force)', $this->invoicesDeleted));
        $this->line(sprintf(' payments deleted      : %d (force)', $this->paymentsDeleted));
        $this->line('--------------------------------------------------------------');
        $this->line($this->dryRun
            ? ' DRY RUN - nothing was written. Re-run with SEED_DRY_RUN=false to apply.'
            : ' Changes applied.');
        $this->line('');
    }

    protected function line(string $message): void
    {
        // Silent outside artisan (e.g. under test).
        if (isset($this->command)) {
            $this->command->getOutput()->writeln($message);
        }
    }
}
