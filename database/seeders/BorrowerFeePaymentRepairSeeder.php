<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-off: undo what paying a borrower fee used to do to the borrower.
 *
 * The borrower fee is the partner's debt to the host, but its invoice is linked to
 * the borrower's subscription, and SubscriptionService::handleSuccessfulPayment()
 * used to treat paying it as the BORROWER paying their enrollment. A borrower whose
 * partner paid the fee was completed (One Time) or had their installments started,
 * without paying anything - and current_cycle_invoice_id was cleared, so they could
 * no longer even pay their own enrollment invoice.
 *
 * ---------------------------------------------------------------------------
 * HOW TO RUN
 * ---------------------------------------------------------------------------
 *   Dry run (DEFAULT - prints every change, writes nothing):
 *     php artisan db:seed --class=BorrowerFeePaymentRepairSeeder
 *
 *   Apply for real (cmd / Laragon terminal):
 *     set "SEED_DRY_RUN=false" && php artisan db:seed --class=BorrowerFeePaymentRepairSeeder
 *     set SEED_DRY_RUN=
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES
 * ---------------------------------------------------------------------------
 * 1. Every borrower whose borrower-fee invoice is Paid: partner_payment_for_me = 1.
 *
 * 2. Every borrower subscription that has a Paid borrower fee but NO paid invoice
 *    of the borrower's own (enrollment / down payment / installment), and was
 *    nevertheless moved on (not Pending, or a payment recorded), is put back to the
 *    state enrollment leaves it in:
 *
 *      status                    Pending
 *      current_phase             installment, for an installment plan with no down
 *                                payment; otherwise enrollment
 *      installments_paid         0
 *      first_payment_date        NULL
 *      subscription_start_date   NULL
 *      subscription_end_date     NULL
 *      enrollment_completed_at   NULL
 *      current_cycle_invoice_id  the borrower's open enrollment invoice
 *
 *    Cancelled subscriptions are skipped. The borrower's own status is not
 *    changed; borrowers left Active are listed in the report.
 */
class BorrowerFeePaymentRepairSeeder extends Seeder
{
    /** Null reads SEED_DRY_RUN, which defaults to a dry run. */
    public ?bool $dryRun = null;

    /** The borrower's own payments - the borrower fee is the partner's. */
    protected const OWN_INVOICE_TYPES = [
        Invoice::INVOICE_TYPE_ENROLLMENT,
        Invoice::INVOICE_TYPE_DOWN_PAYMENT,
        Invoice::INVOICE_TYPE_INSTALLMENT,
    ];

    protected int $flagsSet = 0;
    protected int $subscriptionsReset = 0;

    /** @var array<int,string> */
    protected array $manualReview = [];

    public function run(): void
    {
        $this->dryRun ??= filter_var(env('SEED_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN);

        $this->line('');
        $this->line('==============================================================');
        $this->line(' Borrower fee payment repair');
        $this->line(' MODE: ' . ($this->dryRun ? 'DRY RUN (no writes)' : '*** APPLYING CHANGES ***'));
        $this->line('==============================================================');

        DB::beginTransaction();

        try {
            $this->recordPartnerPayments();
            $this->resetWronglyAdvancedSubscriptions();
            $this->summary();

            // A dry run always rolls back, so nothing slips through a missed guard.
            $this->dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function recordPartnerPayments(): void
    {
        $borrowerIds = Invoice::where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)
            ->where('status', Invoice::STATUS_PAID)
            ->whereNotNull('borrower_id')
            ->distinct()
            ->pluck('borrower_id');

        $borrowers = Borrower::whereIn('id', $borrowerIds)
            ->where(fn($q) => $q->where('partner_payment_for_me', false)->orWhereNull('partner_payment_for_me'))
            ->orderBy('id')
            ->get();

        $this->line('');
        $this->line(sprintf(' partner_payment_for_me -> 1 for %d borrower(s)', $borrowers->count()));

        foreach ($borrowers as $borrower) {
            $this->line(sprintf('     borrower #%d', $borrower->id));

            if (!$this->dryRun) {
                $borrower->update(['partner_payment_for_me' => true]);
            }

            $this->flagsSet++;
        }
    }

    protected function resetWronglyAdvancedSubscriptions(): void
    {
        $subscriptionIds = Invoice::where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)
            ->where('status', Invoice::STATUS_PAID)
            ->whereNotNull('subscription_id')
            ->distinct()
            ->pluck('subscription_id');

        $subscriptions = Subscription::whereIn('id', $subscriptionIds)
            ->where('entity_type', Borrower::class)
            ->where('status', '!=', Subscription::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            $ownInvoices = Invoice::where('subscription_id', $subscription->id)
                ->whereIn('type', self::OWN_INVOICE_TYPES)
                ->orderBy('id')
                ->get();

            // The borrower really paid something: this is not the bug.
            if ($ownInvoices->where('status', Invoice::STATUS_PAID)->isNotEmpty()) {
                continue;
            }

            $wasMovedOn = $subscription->status !== Subscription::STATUS_PENDING
                || (int) $subscription->installments_paid > 0
                || $subscription->first_payment_date
                || $subscription->subscription_start_date
                || $subscription->enrollment_completed_at;

            if (!$wasMovedOn) {
                continue;
            }

            $this->reset($subscription, $ownInvoices);
        }
    }

    protected function reset(Subscription $subscription, $ownInvoices): void
    {
        $openInvoice = $ownInvoices->where('status', Invoice::STATUS_UNPAID)->sortByDesc('id')->first();

        if (!$openInvoice) {
            $this->flagManual($subscription, 'reset to Pending, but the borrower has no Unpaid enrollment '
                . 'invoice to pay - raise one');
        }

        // The phase enrollment starts a subscription in - mirrors
        // SubscriptionCreationService::startSubscription().
        $isInstallment = (int) $subscription->installment_type === 1;
        $hasDownPayment = (float) ($subscription->down_payment ?? 0) > 0;
        $phase = $isInstallment && !$hasDownPayment
            ? Subscription::PHASE_INSTALLMENT
            : Subscription::PHASE_ENROLLMENT;

        $changes = [
            'status' => Subscription::STATUS_PENDING,
            'current_phase' => $phase,
            'installments_paid' => 0,
            'first_payment_date' => null,
            'subscription_start_date' => null,
            'subscription_end_date' => null,
            'enrollment_completed_at' => null,
            'current_cycle_invoice_id' => $openInvoice?->id,
        ];

        $this->line('');
        $this->line(sprintf(
            ' subscription #%-4d Borrower #%-4s  paid only by the partner\'s fee -> back to Pending',
            $subscription->id,
            $subscription->entity_id
        ));

        foreach ($this->diff($subscription, $changes) as $column => [$from, $to]) {
            $this->line(sprintf('     %-26s %-12s -> %s', $column, $this->fmt($from), $this->fmt($to)));
        }

        $borrower = Borrower::find($subscription->entity_id);
        if ($borrower && $borrower->status === Borrower::STATUS_ACTIVE) {
            $this->flagManual($subscription, 'borrower #' . $borrower->id . ' is Active without having paid '
                . '- their status was not changed');
        }

        if (!$this->dryRun) {
            $subscription->update($changes);

            User::where('entity_type', Borrower::class)
                ->where('entity_id', $subscription->entity_id)
                ->get()
                ->each(fn(User $user) => $user->markAsRefresh());
        }

        $this->subscriptionsReset++;
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

        return is_numeric($value) ? (string) round((float) $value, 2) : (string) $value;
    }

    protected function fmt($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $value instanceof \DateTimeInterface ? Carbon::parse($value)->toDateString() : (string) $value;
    }

    protected function flagManual(Subscription $subscription, string $reason): void
    {
        $this->manualReview[] = sprintf(' subscription #%-4d %s', $subscription->id, $reason);
    }

    protected function summary(): void
    {
        if ($this->manualReview) {
            $this->line('');
            $this->line('--------------------------------------------------------------');
            $this->line(' NEEDS MANUAL REVIEW');
            $this->line('--------------------------------------------------------------');
            foreach ($this->manualReview as $row) {
                $this->line($row);
            }
        }

        $this->line('');
        $this->line('--------------------------------------------------------------');
        $this->line(sprintf(' partner_payment_for_me set : %d', $this->flagsSet));
        $this->line(sprintf(' subscriptions reset        : %d', $this->subscriptionsReset));
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
