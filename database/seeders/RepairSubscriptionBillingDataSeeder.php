<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
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
 * Data-repair seeder for the whole billing engine.
 *
 * Rebuilds every subscription, invoice and payment row so it matches what the
 * current code would have produced: retired plans are remapped onto the active
 * Installment plans, and every derived column (phase, counts, amounts, dates,
 * invoice type / linkage) is recomputed from the payments that actually happened.
 *
 * ---------------------------------------------------------------------------
 * HOW TO RUN
 * ---------------------------------------------------------------------------
 *   Dry run (DEFAULT — full before/after report, writes nothing):
 *     php artisan db:seed --class=RepairSubscriptionBillingDataSeeder
 *
 *   Apply for real (PowerShell):
 *     $env:SEED_DRY_RUN="false"; php artisan db:seed --class=RepairSubscriptionBillingDataSeeder
 *
 *   Apply for real (bash):
 *     SEED_DRY_RUN=false php artisan db:seed --class=RepairSubscriptionBillingDataSeeder
 *
 * Back the database up first. The run is a single transaction and a dry run always
 * rolls back, but a successful wrong run is not undoable.
 *
 * Read the two report sections printed at the end before applying:
 *   NEEDS MANUAL REVIEW — rows too ambiguous to touch safely; nothing was changed.
 *   BILLING IMPACT      — rows where the next charge date moves EARLIER, i.e. real
 *                         customers get billed sooner than the old data implied.
 *
 * ---------------------------------------------------------------------------
 * 1. PLANS
 * ---------------------------------------------------------------------------
 * A subscription is remapped when its plan is retired (status = 0) or belongs to
 * the wrong group for the entity (a Borrower must sit on a borrower_enrollment
 * plan, a Partner on partner_enrollment, a User/Employee seat on user_seat):
 *
 *   Certified Partner  "Payment Plan (11-month plan)" -> Certified Partner "Installment", term 11
 *   White-Label (WL)   "Payment Plan (11-month plan)" -> White-Label (WL)  "Installment", term 11
 *   Borrower           "6 Month Payment"              -> Borrower          "Installment", term  6
 *
 * The replacement is resolved by (group, partner type, one-time vs installment,
 * status = 1) — never by hard-coded id — so it behaves identically on local, stage
 * and production. The installment TERM comes from the retired plan's name (11 / 6),
 * not from the replacement's total_installment_count_upto, which is a ceiling (24)
 * rather than a term. Pin an exact plan per subscription in PLAN_OVERRIDES if you
 * disagree with a mapping.
 *
 * ---------------------------------------------------------------------------
 * 2. INVOICES
 * ---------------------------------------------------------------------------
 * Every invoice in the system predates the subscription_id / type / month_count
 * columns, so all of them are NULL. Each invoice is linked to its subscription and
 * typed the way the current code types it:
 *
 *   is_platform_fee = 1                         -> platform_fee
 *   partner-paid, for a borrower, at the borrower-fee plan amount
 *                                               -> borrower_fee   (one-off, never a cycle)
 *   already a commission payout                 -> commission_payout (value normalised, never linked)
 *   oldest remaining invoice                    -> down_payment if down_payment > 0, else enrollment
 *   every later one                             -> installment
 *
 * A borrower fee is owed by the PARTNER, so any such invoice still addressed to the
 * borrower is re-addressed to the partner (entity_type / entity_id / customer_name /
 * email / country) while keeping borrower_id, matching the invoice EnrollmentService
 * builds for the same fee.
 *
 * Only one billing cycle may be open at a time, so unpaid invoices superseded by a
 * newer one are marked Expired — exactly what createInvoiceIfDue() writes when it
 * rolls a missed cycle forward — and the newest open invoice becomes the
 * subscription's current_cycle_invoice_id. One-off charges (borrower fee, seat,
 * commission payout) are never eligible to be the current cycle, and
 * current_cycle_invoice_id is NULL when no cycle invoice is open.
 *
 * ---------------------------------------------------------------------------
 * 2b. SUBSCRIPTIONS BUILT FROM A NON-ENROLLMENT PLAN
 * ---------------------------------------------------------------------------
 * A subscription created from a user_seat plan is a mis-creation: a seat is a
 * recurring per-head charge, not an enrollment, so no subscription should exist for
 * it. Such a row is SOFT-DELETED. Its invoices are kept, typed, re-addressed to the
 * partner and detached (subscription_id -> NULL) first, so the money stays on the
 * books and nothing points at a deleted parent. Pin the row in PLAN_OVERRIDES
 * instead if it really is a genuine enrollment on the wrong plan.
 *
 * A borrower_fee row - enrollment_type "Fixed Enrollment" - is NOT deleted. It used
 * to be, on the same reasoning. It is now CONVERTED to a One Time Payment
 * enrollment, because the money was collected and the customer is enrolled:
 *
 *   subscription_plan_id   the entity's active One Time Payment enrollment plan
 *                          (Borrower -> borrower_enrollment, Partner -> its type)
 *   enrollment_type        One Time Payment
 *   installment_count      1
 *   installments_paid      1 when any invoice of its own was paid, else 0
 *   current_phase          completed
 *   subscription_end_date  NULL
 *   subscription_end_date  NULL
 *   deleted_at             NULL - a row deleted by an earlier run is restored
 *
 * The amount stays what was actually invoiced (the borrower fee), not the list
 * price of the One Time Payment plan it now points at - the seeder's rule
 * everywhere else. Pin MONTHLY_AMOUNT_OVERRIDES / PLAN_OVERRIDES to disagree.
 *
 * ---------------------------------------------------------------------------
 * 2c. THE CYCLE DATE IS NOT REPAIRED
 * ---------------------------------------------------------------------------
 * subscription_end_date says when a customer is next charged. That is live billing
 * data, not something this seeder can reconstruct from history, so an existing
 * value is left exactly as it is.
 *
 * The single exception is clearing it. A subscription with nothing left to bill
 * must carry NULL: both portals lock a profile once today is past
 * subscription_end_date, so a date left on a borrower who has paid in full locks
 * them out of their own account.
 *
 * ---------------------------------------------------------------------------
 * 3. SUBSCRIPTION COLUMNS
 * ---------------------------------------------------------------------------
 * Recomputed from the repaired invoices, mirroring handleOneTimePayment(),
 * handleInstallmentPayment() and SubscriptionCreationService::startSubscription():
 *
 *   installment_count      installment: the term; one-time: 1
 *   installments_paid      one-time paid: 1; installment: count of PAID
 *                          enrollment + installment invoices. A down payment is
 *                          phase progress, not an installment, and borrower-fee /
 *                          platform-fee / commission rows never count.
 *   subscription_amount_per_month
 *                          installment: the monthly figure (see AMOUNTS below)
 *                          one-time:    equals payable_amount
 *   total_subscription_amount / payable_amount
 *                          installment: total = monthly x term, payable = down + total
 *                          one-time:    both = the amount actually invoiced
 *   monthly_platform_fee   Borrower: always 0. Partner: the plan's platform fee.
 *   borrower_fee_amount    the borrower-fee invoice amount, when one exists
 *   user_seats/per_seat_amount
 *                          Partners only, from the active user_seat plan for their
 *                          partner type. per_seat_amount is currently NULL on every
 *                          partner, which silently disables seat billing entirely:
 *                          EmployeeService/UserService read it when raising a seat
 *                          invoice, and createInvoiceForSubscription() bills
 *                          PHASE_USER_SEAT from it, while a 0/NULL amount is
 *                          dropped by the zero-amount guard.
 *   current_phase          one-time paid  -> completed (Borrower) / platform_fee (Partner)
 *                          installments done -> same
 *                          unpaid down payment -> enrollment
 *                          otherwise           -> installment
 *   status                 only ever promoted Pending -> Active once real money
 *                          arrived. Never set to Completed (PHASE_COMPLETED is what
 *                          records "nothing left to bill"), and a deliberate
 *                          Cancelled / Expired / Past Due is left untouched.
 *   subscription_end_date  the one cycle date — what the customer is paid through
 *                          AND when the cron next bills them.
 *                          Left untouched when one is already set. Cleared to
 *                          NULL when nothing is left to bill: a borrower who has
 *                          paid in full, or a fee-settled row.
 *   enrollment_completed_at
 *                          the date enrollment finished, when it has
 *
 * ---------------------------------------------------------------------------
 * 4. PAYMENTS
 * ---------------------------------------------------------------------------
 * borrower_id / partner_id / entity_* / is_partner_paying are re-derived from the
 * invoice, the way UnifiedPaymentService writes them on a live charge.
 *
 * ---------------------------------------------------------------------------
 * AMOUNTS — the one judgment call, read before applying
 * ---------------------------------------------------------------------------
 * Per EnrollmentService::calculateBorrowerAmounts(), on an installment plan:
 *   payable_amount            = full price agreed with the customer
 *   total_subscription_amount = remaining balance = payable_amount - down_payment
 *   subscription_amount_per_month = remaining balance / term
 *
 * The retired-plan rows stored the MONTHLY figure in the total columns (500 stored,
 * while the plan is 3000 over 6 months and the invoices actually raised were 500
 * each). So the amount that was really INVOICED is treated as the source of truth
 * for the monthly figure, and the totals are rebuilt from it:
 *
 *   monthly = MONTHLY_AMOUNT_OVERRIDES[id]
 *           | subscription_amount_per_month, when already valid
 *           | most frequent enrollment/installment invoice amount
 *           | plan total / term
 *
 * Pin anything you disagree with in MONTHLY_AMOUNT_OVERRIDES rather than editing
 * rows by hand, so the repair stays reproducible.
 */
class RepairSubscriptionBillingDataSeeder extends Seeder
{
    /**
     * Retired enrollment_type_slug => the real installment term.
     *
     * Keyed on the RETIRED plan because the replacement Installment plans all carry
     * total_installment_count_upto = 24, a ceiling rather than a term.
     */
    protected const INSTALLMENT_TERM_BY_LEGACY_SLUG = [
        'payment-plan-11-month-plan' => 11,
        '6-month-payment'            => 6,
    ];

    /** Manual pins: subscription id => monthly installment amount. */
    protected const MONTHLY_AMOUNT_OVERRIDES = [
        // 86 => 150.00,
    ];

    /** Manual pins: subscription id => subscription_plan id, when auto-mapping is wrong. */
    protected const PLAN_OVERRIDES = [
        // 54 => 5,
    ];

    protected bool $dryRun = true;

    /** Cached borrower-fee plan amount; false means "not looked up yet". */
    protected float|null|false $borrowerFeeAmount = false;

    /** @var array<int,string> */
    protected array $manualReview = [];

    /** @var array<int,string> */
    protected array $billingImpact = [];

    protected int $subscriptionsRepaired = 0;
    protected int $subscriptionsDeleted = 0;
    protected int $subscriptionsRestored = 0;
    protected int $invoicesRepaired = 0;
    protected int $paymentsRepaired = 0;
    protected int $usersFlagged = 0;

    /**
     * Invoice ids already handled while walking the subscriptions.
     *
     * Tracked explicitly because a dry run only applies its changes in memory, so
     * re-querying for whereNull('subscription_id') afterwards would list invoices
     * that were in fact just linked, and report them as orphans.
     *
     * @var array<int,true>
     */
    protected array $handledInvoiceIds = [];
    protected int $plansRemapped = 0;

    public function run(): void
    {
        $this->dryRun = filter_var(env('SEED_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN);

        $this->line('');
        $this->line('==============================================================');
        $this->line(' Subscription / invoice / payment data repair');
        $this->line(' MODE: ' . ($this->dryRun ? 'DRY RUN (no writes)' : '*** APPLYING CHANGES ***'));
        $this->line('==============================================================');

        DB::beginTransaction();

        try {
            // entity is eager loaded because the app runs with
            // Model::preventLazyLoading() in non-production environments.
            // Soft-deleted Fixed Enrollment rows are pulled back in: an earlier
            // run dissolved them as mis-creations, and they are now converted to a
            // One Time Payment enrollment instead, so they have to be reachable
            // again. Every other deleted row stays deleted.
            $fixedEnrollmentPlanIds = SubscriptionPlan::withTrashed()
                ->where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE)
                ->pluck('id');

            $subscriptions = Subscription::with('entity')
                ->withTrashed()
                ->where(function ($q) use ($fixedEnrollmentPlanIds) {
                    $q->whereNull('deleted_at')
                        ->orWhereIn('subscription_plan_id', $fixedEnrollmentPlanIds);
                })
                ->orderBy('id')
                ->get();

            $this->line(sprintf(' %d subscription(s) to inspect', $subscriptions->count()));

            foreach ($subscriptions as $subscription) {
                $this->repairSubscription($subscription);
            }

            $this->normaliseUnlinkedInvoices();
            $this->flagUsersForRefresh();
            $this->summary();

            // A dry run always rolls back, so even a write that slipped past a guard
            // is undone rather than left half-applied.
            $this->dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Subscription
    // ------------------------------------------------------------------

    protected function repairSubscription(Subscription $subscription): void
    {
        $entity = $subscription->entity;

        if (!$entity) {
            $this->flagManual($subscription, 'entity ' . class_basename($subscription->entity_type)
                . ' #' . $subscription->entity_id . ' no longer exists');
            return;
        }

        $isBorrower = $subscription->entity_type === Borrower::class;
        $isPartner  = $subscription->entity_type === Partner::class;
        $isSeat     = in_array($subscription->entity_type, [User::class, Employee::class], true);

        if ($isSeat) {
            $this->repairSeatSubscription($subscription, $entity);
            return;
        }

        if (!$isBorrower && !$isPartner) {
            $this->flagManual($subscription, 'unrecognised entity type ' . $subscription->entity_type);
            return;
        }

        // A subscription sitting on a borrower_fee or user_seat plan is not an
        // enrollment at all. The borrower fee is the one-off amount a non-host
        // partner owes the host for each borrower — EnrollmentService raises it as a
        // single invoice against the PARTNER and never creates a subscription for
        // it. A row like this is a mis-creation: there is no enrollment amount to
        // recover, so inventing one would be worse than leaving it alone. Its
        // invoices are still typed correctly below, then it is handed to a human.
        $currentPlan = $subscription->subscription_plan_id
            ? SubscriptionPlan::withTrashed()->find($subscription->subscription_plan_id)
            : null;

        if ($currentPlan
            && $currentPlan->group === SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT
            && !isset(self::PLAN_OVERRIDES[$subscription->id])) {
            $this->dissolveNonEnrollmentSubscription($subscription, $currentPlan);
            return;
        }

        // A borrower_fee row is the "Fixed Enrollment" case. It used to be
        // dissolved alongside the seat rows, on the reasoning that the borrower fee
        // is a one-off charge the partner owes and never an enrollment. It is now
        // kept and converted instead: the money was collected and the customer is
        // enrolled, so the row is remapped onto the entity's One Time Payment plan
        // below, settled in full, and taken off the billing schedule.
        $wasFixedEnrollment = $currentPlan
            && $currentPlan->group === SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE
            && !isset(self::PLAN_OVERRIDES[$subscription->id]);

        $resolved = $this->resolvePlan($subscription, $entity, $isBorrower);

        if (!$resolved) {
            $this->flagManual($subscription, 'no active '
                . ($isBorrower ? 'borrower_enrollment' : 'partner_enrollment')
                . ' plan matches this subscription — create the plan, then re-run');
            return;
        }

        [$plan, $term, $isInstallment, $remapped] = $resolved;

        // Invoices first: phase, paid-through date and installments_paid are all
        // rebuilt from the repaired invoice rows.
        $invoices = $this->repairInvoicesFor($subscription);

        $downPayment = (float) ($subscription->down_payment ?? 0);

        // Paid rows that count as progress. borrower_fee / platform_fee /
        // commission_payout are separate charges and never count.
        $paidCycle = $invoices
            ->where('status', Invoice::STATUS_PAID)
            ->whereIn('type', [Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::INVOICE_TYPE_INSTALLMENT]);

        $paidDown = $invoices
            ->where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::INVOICE_TYPE_DOWN_PAYMENT);

        // A row whose only settled charge is the borrower fee - the "Fixed
        // Enrollment" case. Recognised from its invoices and its amount rather than
        // from its plan, because the plan is exactly what the conversion changes:
        // keying on the plan made the row report itself unpaid again the second
        // time the seeder ran, undoing its own repair. The amount has to match too,
        // so a fee collected alongside a genuinely unpaid enrollment is never
        // mistaken for payment of it.
        $feePaid = $invoices
            ->where('status', Invoice::STATUS_PAID)
            ->where('type', Invoice::INVOICE_TYPE_BORROWER_FEE)
            ->sortBy(fn($i) => $this->invoicePaidAt($i));

        $settledByFeeOnly = !$isInstallment
            && $paidCycle->isEmpty()
            && $paidDown->isEmpty()
            && $feePaid->isNotEmpty()
            && abs((float) $feePaid->sum('payment_amount') - (float) ($subscription->payable_amount ?? 0)) < 0.01;

        $allPaid = $paidCycle->merge($paidDown)
            ->sortBy(fn($i) => $this->invoicePaidAt($i));

        $hasAnyPayment = $allPaid->isNotEmpty();
        $firstPaidAt   = $allPaid->isNotEmpty() ? $this->invoicePaidAt($allPaid->first()) : null;
        $lastPaidAt    = $allPaid->isNotEmpty() ? $this->invoicePaidAt($allPaid->last()) : null;

        // The counters above only read enrollment / installment / down payment
        // invoices, so a fee-settled row looks unpaid to them. The fee is the
        // payment, and its dates are the payment dates - without them "enrollment
        // completed" has nothing to point at and falls back to now(), stamping
        // today onto an enrollment settled months ago.
        if ($settledByFeeOnly) {
            $hasAnyPayment = true;
            $firstPaidAt   = $this->invoicePaidAt($feePaid->first());
            $lastPaidAt    = $this->invoicePaidAt($feePaid->last());
        }

        if ($isInstallment) {
            $monthly    = $this->resolveMonthlyAmount($subscription, $invoices, $plan, $term);
            $total      = round($monthly * $term, 2);
            $payable    = round($downPayment + $total, 2);
            $paidCount  = $paidCycle->count();
            $isComplete = $paidCount >= $term;
        } else {
            // One-time: a single charge. The amount actually invoiced outranks the
            // stored column, which on mis-assigned rows came from the wrong plan.
            $payable    = $this->resolveOneTimeAmount($subscription, $invoices, $plan);
            $total      = $payable;
            $monthly    = $payable; // startSubscription() mirrors payable_amount here
            $term       = 1;
            $paidCount  = $hasAnyPayment ? 1 : 0;
            $isComplete = $hasAnyPayment;
        }

        // current_phase mirrors startSubscription() and the payment handlers.
        if ($isComplete) {
            $phase = $isBorrower ? Subscription::PHASE_COMPLETED : Subscription::PHASE_PLATFORM_FEE;
        } elseif ($downPayment > 0 && $paidDown->isEmpty()) {
            $phase = Subscription::PHASE_ENROLLMENT;
        } elseif ($isInstallment) {
            $phase = Subscription::PHASE_INSTALLMENT;
        } else {
            $phase = Subscription::PHASE_ENROLLMENT;
        }

        // A fee-settled row is done: one payment, nothing left. Stated outright
        // rather than left to the branch above, which would put a Partner into
        // platform_fee.
        if ($settledByFeeOnly && $isComplete) {
            $phase = Subscription::PHASE_COMPLETED;
        }

        // Only ever promote Pending -> Active. A deliberate Cancelled / Expired /
        // Past Due is left as the business set it — this seeder cannot know why.
        $status = $subscription->status;
        if ($hasAnyPayment && $status === Subscription::STATUS_PENDING) {
            $status = Subscription::STATUS_ACTIVE;
        }

        // A borrower that finished paying owes nothing further. A partner rolls into
        // the recurring platform fee and must keep a live schedule.
        $nothingLeftToBill = $isComplete && $isBorrower;

        // The cycle date a row already carries is real billing data - it says when
        // the customer is next charged - so the repair leaves it exactly as it is.
        // It is recomputed in one case only: a subscription with nothing left to
        // bill must carry NULL, because both portals lock a profile once today is
        // past subscription_end_date, so a date left on a finished borrower locks
        // them out of their own account.
        $cycleDate = $subscription->subscription_end_date
            ? Carbon::parse($subscription->subscription_end_date)->startOfDay()
            : null;

        if ($nothingLeftToBill || $settledByFeeOnly) {
            $cycleDate = null;
        }

        // A cycle date equal to the start date is a zero-length billing period.
        // Rows like this were written as "paid through the day they paid", so they
        // read as overdue from the instant they were settled, and both portals lock
        // a profile once today is past subscription_end_date - so the customer was
        // locked out for the act of paying.
        //
        // A payment buys a month, so the date the row should carry is one month past
        // the cycle payment that set it. This is the ONLY case where an existing
        // date is recomputed: everywhere else it is left exactly as it is.
        if ($cycleDate && $subscription->subscription_start_date) {
            $startDate = Carbon::parse($subscription->subscription_start_date)->startOfDay();

            if ($cycleDate->equalTo($startDate)) {
                $lastCyclePaidAt = $invoices
                    ->where('status', Invoice::STATUS_PAID)
                    ->filter(fn($i) => in_array($i->type, Invoice::CYCLE_INVOICE_TYPES, true) || !$i->type)
                    ->map(fn($i) => $this->invoicePaidAt($i))
                    ->filter()
                    ->sort()
                    ->last();

                if ($lastCyclePaidAt) {
                    $cycleDate = Carbon::parse($lastCyclePaidAt)->startOfDay()->addMonth();

                    $this->flagManual($subscription, sprintf(
                        'cycle date equalled the start date (%s) - rebuilt as one month past the '
                            . 'last cycle payment, %s. %s',
                        $startDate->toDateString(),
                        $cycleDate->toDateString(),
                        $cycleDate->isPast()
                            ? 'Still in the past, so they are genuinely behind: one invoice will be '
                                . 'raised on the next cron run and they stay locked out until it is paid.'
                            : 'Now in the future.'
                    ));
                }
            }
        }

        // The one open billing cycle. One-off fee rows are never the current cycle.
        $openInvoice = $invoices
            ->reject(fn($i) => $this->isOneOffInvoice($i))
            ->where('status', Invoice::STATUS_UNPAID)
            ->sortByDesc('id')
            ->first();

        $borrowerFeeInvoice = $invoices->firstWhere('type', Invoice::INVOICE_TYPE_BORROWER_FEE);

        $changes = [
            'subscription_plan_id'          => $plan->id,
            'enrollment_type'               => $plan->enrollment_type,
            'partner_type'                  => $plan->partner_type ?: null,
            'installment_type'              => $isInstallment ? 1 : 0,
            'installment_count'             => $term,
            'monthly_platform_fee'          => $isBorrower ? 0 : (float) ($plan->platform_fee ?? 0),
            'subscription_amount_per_month' => $monthly,
            'total_subscription_amount'     => $total,
            'payable_amount'                => $payable,
            'down_payment'                  => $downPayment,
            'is_custom'                     => abs($total - (float) ($plan->total_amount ?? 0)) > 0.01 ? 1 : 0,
            'installments_paid'             => $paidCount,
            'current_phase'                 => $phase,
            'status'                        => $status,
            'subscription_start_date'       => $subscription->subscription_start_date
                ?: ($firstPaidAt ? Carbon::parse($firstPaidAt)->toDateString() : null),
            'first_payment_date'            => $subscription->first_payment_date
                ?: ($firstPaidAt ? Carbon::parse($firstPaidAt)->toDateString() : null),
            'subscription_end_date'         => $cycleDate?->toDateString(),
            'enrollment_completed_at'       => $isComplete
                ? ($subscription->enrollment_completed_at ?: ($lastPaidAt ? Carbon::parse($lastPaidAt) : now()))
                : null,
            'current_cycle_invoice_id'      => $openInvoice?->id,
        ];

        if ($borrowerFeeInvoice) {
            $changes['borrower_fee_amount'] = (float) $borrowerFeeInvoice->payment_amount;
        }

        // Undo the earlier run's deletion. Converting a row that stays soft-deleted
        // would change nothing a user can see.
        if ($wasFixedEnrollment && $subscription->trashed()) {
            $changes['deleted_at'] = null;
            $this->subscriptionsRestored++;
        }

        if ($isPartner) {
            // Seat pricing lives on the partner's subscription: EmployeeService and
            // UserService read per_seat_amount from here when raising a seat invoice.
            $seatPlan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
                ->where('partner_type', $plan->partner_type)
                ->where('status', true)
                ->first();

            if ($seatPlan) {
                $changes['user_seats']      = $seatPlan->total_seat_count;
                $changes['per_seat_amount'] = (float) $seatPlan->total_amount;
            } else {
                $this->flagManual($subscription, 'no active user_seat plan for partner type "'
                    . $plan->partner_type . '" — per_seat_amount left NULL, seat billing stays disabled');
            }
        }

        $this->recordBillingImpact($subscription, $cycleDate);

        $diff = $this->diff($subscription, $changes);

        $this->line('');
        $this->line(sprintf(
            ' subscription #%-4d %-8s #%-4s  plan %s%s  %s',
            $subscription->id,
            class_basename($subscription->entity_type),
            $subscription->entity_id,
            $subscription->subscription_plan_id,
            $remapped ? ' -> ' . $plan->id . ' (REMAPPED)' : '',
            $diff ? count($diff) . ' column(s) change' : 'already correct'
        ));

        foreach ($diff as $column => [$from, $to]) {
            $this->line(sprintf('     %-31s %-22s -> %s', $column, $this->fmt($from), $this->fmt($to)));
        }

        if ($diff && !$this->dryRun) {
            $subscription->update($changes);
        }

        if ($diff) {
            $this->subscriptionsRepaired++;
        }

        if ($remapped) {
            $this->plansRemapped++;
        }

        $this->repairPaymentsFor($invoices);
    }

    /**
     * Delete a subscription that was created from a non-enrollment plan.
     *
     * The borrower fee ("Fixed Enrollment") is the one-off amount a non-host partner
     * owes the host for each borrower it brings on. EnrollmentService raises it as a
     * single invoice against the PARTNER, carrying the borrower_id for traceability,
     * and never creates a subscription for it — so a subscription row built from
     * that plan is a mis-creation with no enrollment amount behind it.
     *
     * The charge itself is real and stays: its invoice is re-addressed to the
     * partner (keeping borrower_id), typed borrower_fee, and detached from the
     * subscription before the row is soft-deleted, so nothing points at a deleted
     * parent and the money remains attributable to both partner and borrower.
     */
    protected function dissolveNonEnrollmentSubscription(Subscription $subscription, SubscriptionPlan $plan): void
    {
        $invoices = $this->findInvoicesFor($subscription);

        $this->line('');
        $this->line(sprintf(
            ' subscription #%-4d %-8s #%-4s  DELETE — built from the "%s" plan #%d ("%s"), not an enrollment plan',
            $subscription->id,
            class_basename($subscription->entity_type),
            $subscription->entity_id,
            $plan->group,
            $plan->id,
            $plan->enrollment_type
        ));

        foreach ($invoices as $invoice) {
            $changes = ['month_count' => $invoice->month_count ?: 1];

            if ($this->looksLikeBorrowerFee($invoice)) {
                $changes['type'] = Invoice::INVOICE_TYPE_BORROWER_FEE;
                $changes += $this->partnerAddressing($invoice);
            } elseif (!$invoice->type) {
                $changes['type'] = $this->resolveInvoiceType($invoice, null, 0.0);
            }

            // Never leave an invoice pointing at a row that is about to disappear.
            $changes['subscription_id'] = null;

            $this->applyInvoiceChanges($invoice, $changes);
        }

        $this->repairPaymentsFor($invoices);

        if (!$this->dryRun) {
            $subscription->delete(); // soft delete — recoverable
        }

        $this->subscriptionsDeleted++;

        $this->flagManual($subscription, sprintf(
            'DELETED (soft). Its %d invoice(s) were kept, detached and re-addressed to the partner. '
                . 'If %s #%s still needs a real enrollment, enrol them again so a proper subscription is created.',
            $invoices->count(),
            class_basename($subscription->entity_type),
            $subscription->entity_id
        ));
    }

    /**
     * Re-address a borrower-fee invoice to the partner who owes it, keeping
     * borrower_id so the charge stays traceable to the borrower it was raised for.
     * Mirrors the invoice EnrollmentService builds for the same fee.
     */
    protected function partnerAddressing(Invoice $invoice): array
    {
        if ($invoice->entity_type === Partner::class || !$invoice->partner_id) {
            return [];
        }

        $partner = Partner::find($invoice->partner_id);

        if (!$partner) {
            return [];
        }

        return [
            'entity_type'       => Partner::class,
            'entity_id'         => $partner->id,
            'customer_name'     => $partner->company_name ?: $partner->full_name,
            'email'             => $partner->email,
            'country'           => $partner->country,
            'is_partner_paying' => 1,
        ];
    }

    /**
     * User/Employee seat subscription: no enrollment ladder, just a recurring seat
     * charge, so only the seat price and phase are rebuilt here.
     */
    protected function repairSeatSubscription(Subscription $subscription, $entity): void
    {
        $partner = Partner::find($entity->partner_id ?? null);

        if (!$partner) {
            $this->flagManual($subscription, 'seat subscription has no resolvable partner');
            return;
        }

        $seatPlan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
            ->where('partner_type', $partner->partner_type)
            ->where('status', true)
            ->first();

        if (!$seatPlan) {
            $this->flagManual($subscription, 'no active user_seat plan for partner type "'
                . $partner->partner_type . '"');
            return;
        }

        $invoices = $this->repairInvoicesFor($subscription);
        $amount   = (float) $seatPlan->total_amount;

        $changes = [
            'subscription_plan_id' => $seatPlan->id,
            'partner_type'         => $partner->partner_type,
            'enrollment_type'      => $seatPlan->enrollment_type,
            'current_phase'        => Subscription::PHASE_USER_SEAT,
            // Both are written at creation and billed from per_seat_amount.
            'per_seat_amount'      => $subscription->per_seat_amount ?: $amount,
            'monthly_platform_fee' => $subscription->monthly_platform_fee ?: $amount,
            'user_seats'           => 1,
        ];

        $diff = $this->diff($subscription, $changes);

        $this->line('');
        $this->line(sprintf(
            ' subscription #%-4d %-8s #%-4s  (seat)  %s',
            $subscription->id,
            class_basename($subscription->entity_type),
            $subscription->entity_id,
            $diff ? count($diff) . ' column(s) change' : 'already correct'
        ));

        foreach ($diff as $column => [$from, $to]) {
            $this->line(sprintf('     %-31s %-22s -> %s', $column, $this->fmt($from), $this->fmt($to)));
        }

        if ($diff && !$this->dryRun) {
            $subscription->update($changes);
        }

        if ($diff) {
            $this->subscriptionsRepaired++;
        }

        $this->repairPaymentsFor($invoices);
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    /**
     * Decide which plan a subscription belongs on.
     *
     * @return array{0:SubscriptionPlan,1:int,2:bool,3:bool}|null [plan, term, isInstallment, wasRemapped]
     */
    protected function resolvePlan(Subscription $subscription, $entity, bool $isBorrower): ?array
    {
        $current = $subscription->subscription_plan_id
            ? SubscriptionPlan::withTrashed()->find($subscription->subscription_plan_id)
            : null;

        $expectedGroup = $isBorrower
            ? SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT
            : SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT;

        // An installment subscription is one whose plan says so, whose
        // installment_type flag is set, or whose retired plan is a known term plan.
        $legacySlug     = (string) ($current->enrollment_type_slug ?? '');
        $isInstallment  = ($current && $current->isInstallmentPlan())
            || (int) $subscription->installment_type === 1
            || isset(self::INSTALLMENT_TERM_BY_LEGACY_SLUG[$legacySlug]);

        if (isset(self::PLAN_OVERRIDES[$subscription->id])) {
            $pinned = SubscriptionPlan::find(self::PLAN_OVERRIDES[$subscription->id]);

            if ($pinned) {
                return [$pinned, $this->resolveTerm($subscription, $current, $pinned, $isInstallment), $isInstallment, true];
            }
        }

        $needsRemap = !$current
            || !$current->status
            || $current->group !== $expectedGroup;

        if (!$needsRemap) {
            return [$current, $this->resolveTerm($subscription, $current, $current, $isInstallment), $isInstallment, false];
        }

        if ($current && $current->group !== $expectedGroup) {
            $this->flagManual(
                $subscription,
                sprintf(
                    'plan #%d is in group "%s" but a %s must sit on "%s" — remapped, verify the amounts',
                    $current->id,
                    $current->group ?: 'NULL',
                    $isBorrower ? 'Borrower' : 'Partner',
                    $expectedGroup
                )
            );
        }

        $partnerType = $isBorrower
            ? null
            : ($entity->partner_type ?: $subscription->partner_type);

        $replacement = SubscriptionPlan::query()
            ->where('status', true)
            ->where('group', $expectedGroup)
            ->where(
                'enrollment_type_slug',
                $isInstallment
                    ? SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG
                    : SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG
            )
            ->where(function ($q) use ($partnerType) {
                // Borrower plans store partner_type as either NULL or ''.
                if ($partnerType === null || $partnerType === '') {
                    $q->whereNull('partner_type')->orWhere('partner_type', '');
                } else {
                    $q->where('partner_type', $partnerType);
                }
            })
            ->orderBy('id')
            ->first();

        if (!$replacement) {
            return null;
        }

        return [$replacement, $this->resolveTerm($subscription, $current, $replacement, $isInstallment), $isInstallment, true];
    }

    /**
     * Installment term: the retired plan's real term first (its name carries it),
     * then whatever the subscription already had, then the plan's ceiling.
     */
    protected function resolveTerm(
        Subscription $subscription,
        ?SubscriptionPlan $current,
        SubscriptionPlan $target,
        bool $isInstallment
    ): int {
        if (!$isInstallment) {
            return 1;
        }

        $legacySlug = (string) ($current->enrollment_type_slug ?? '');

        if (isset(self::INSTALLMENT_TERM_BY_LEGACY_SLUG[$legacySlug])) {
            return self::INSTALLMENT_TERM_BY_LEGACY_SLUG[$legacySlug];
        }

        $existing = (int) ($subscription->installment_count ?? 0);
        if ($existing > 0) {
            return $existing;
        }

        return max(1, (int) ($target->total_installment_count_upto ?? 1));
    }

    // ------------------------------------------------------------------
    // Amounts
    // ------------------------------------------------------------------

    protected function resolveMonthlyAmount(
        Subscription $subscription,
        $invoices,
        SubscriptionPlan $plan,
        int $term
    ): float {
        if (isset(self::MONTHLY_AMOUNT_OVERRIDES[$subscription->id])) {
            return round((float) self::MONTHLY_AMOUNT_OVERRIDES[$subscription->id], 2);
        }

        $stored = (float) ($subscription->subscription_amount_per_month ?? 0);
        if ($stored > 0) {
            return round($stored, 2);
        }

        $modal = $this->modalCycleAmount($invoices);
        if ($modal !== null) {
            return $modal;
        }

        $planTotal = (float) ($plan->total_amount ?? 0);

        return $term > 0 ? round($planTotal / $term, 2) : $planTotal;
    }

    /**
     * One-time price: what was actually invoiced beats the stored column, which on a
     * mis-assigned row was copied from the wrong plan.
     */
    protected function resolveOneTimeAmount(Subscription $subscription, $invoices, SubscriptionPlan $plan): float
    {
        $modal = $this->modalCycleAmount($invoices);
        if ($modal !== null) {
            return $modal;
        }

        $stored = (float) ($subscription->payable_amount ?? 0);
        if ($stored > 0) {
            return round($stored, 2);
        }

        return round((float) ($plan->total_amount ?? 0), 2);
    }

    /**
     * Most frequent enrollment/installment invoice amount. Down payments, borrower
     * fees, platform fees and commission payouts are excluded — none of them is the
     * recurring or headline figure.
     */
    protected function modalCycleAmount($invoices): ?float
    {
        $amounts = $invoices
            ->whereIn('type', [Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::INVOICE_TYPE_INSTALLMENT])
            ->pluck('payment_amount')
            ->map(fn($a) => (string) round((float) $a, 2))
            ->filter(fn($a) => (float) $a > 0);

        if ($amounts->isEmpty()) {
            return null;
        }

        return round((float) $amounts->countBy()->sortDesc()->keys()->first(), 2);
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    /**
     * Link and type every invoice belonging to a subscription, and expire unpaid
     * ones a newer invoice already superseded.
     *
     * Invoices predate subscription_id, so they are matched by borrower / partner
     * and then linked. Returns the repaired collection for the rebuild to read.
     */
    protected function repairInvoicesFor(Subscription $subscription)
    {
        $invoices = $this->findInvoicesFor($subscription);

        if ($invoices->isEmpty()) {
            return $invoices;
        }

        $downPayment = (float) ($subscription->down_payment ?? 0);

        // The oldest non-one-off invoice is the signup invoice; everything after it
        // is a cron-generated installment.
        $firstCycle = $invoices
            ->reject(fn($i) => $this->isOneOffInvoice($i))
            ->sortBy('id')
            ->first();

        foreach ($invoices as $invoice) {
            $type = $this->resolveInvoiceType($invoice, $firstCycle, $downPayment);

            $changes = ['month_count' => $invoice->month_count ?: 1];

            if ($type !== $invoice->type) {
                $changes['type'] = $type;
            }

            // A borrower fee is owed by the partner, so it must be addressed to the
            // partner even on older rows that addressed it to the borrower.
            if ($type === Invoice::INVOICE_TYPE_BORROWER_FEE) {
                $changes += $this->partnerAddressing($invoice);
            }

            // A commission payout is not part of any subscription's billing cycle,
            // so it is typed correctly but never linked.
            if ($type !== Invoice::INVOICE_TYPE_COMMISSION_PAYOUT) {
                $changes['subscription_id'] = $subscription->id;
            }

            $this->applyInvoiceChanges($invoice, $changes);
        }

        // Only one billing cycle may be open at a time. Older unpaid cycle invoices
        // were superseded and should read Expired, which is what createInvoiceIfDue()
        // writes when it rolls a missed cycle forward.
        $openCycles = $invoices
            ->reject(fn($i) => $this->isOneOffInvoice($i))
            ->where('status', Invoice::STATUS_UNPAID)
            ->sortByDesc('id');

        foreach ($openCycles->slice(1) as $stale) {
            $this->applyInvoiceChanges($stale, ['status' => Invoice::STATUS_EXPIRED], '(superseded)');
        }

        return $invoices;
    }

    /**
     * Every invoice belonging to a subscription. Invoices predate subscription_id,
     * so unlinked ones are matched by borrower / partner instead.
     */
    protected function findInvoicesFor(Subscription $subscription)
    {
        $invoices = Invoice::query()
            ->where(function ($q) use ($subscription) {
                $q->where('subscription_id', $subscription->id);

                if ($subscription->entity_type === Borrower::class) {
                    // Includes the partner-paid borrower-fee invoice, which
                    // EnrollmentService also links to the borrower's subscription.
                    $q->orWhere(function ($sub) use ($subscription) {
                        $sub->whereNull('subscription_id')
                            ->where('borrower_id', $subscription->entity_id);
                    });
                } else {
                    $q->orWhere(function ($sub) use ($subscription) {
                        $sub->whereNull('subscription_id')
                            ->where('partner_id', $subscription->entity_id)
                            ->whereNull('borrower_id')
                            // A seat invoice is billed to the partner with no
                            // borrower, so it looks identical to a partner
                            // enrollment invoice here — but it belongs to the
                            // User/Employee seat subscription, not this one.
                            ->where(function ($t) {
                                $t->whereNull('type')
                                    ->orWhereNotIn('type', [
                                        Invoice::INVOICE_TYPE_USER_SEAT,
                                        Invoice::INVOICE_TYPE_BORROWER_FEE,
                                    ]);
                            });
                    });
                }
            })
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $this->handledInvoiceIds[$invoice->id] = true;
        }

        return $invoices;
    }

    /**
     * Type any invoice the way the current code would type it.
     */
    protected function resolveInvoiceType(Invoice $invoice, ?Invoice $firstCycle, float $downPayment): string
    {
        // Normalise the malformed "commission payout" value onto the constant.
        if ($invoice->type && str_replace(' ', '_', strtolower($invoice->type)) === Invoice::INVOICE_TYPE_COMMISSION_PAYOUT) {
            return Invoice::INVOICE_TYPE_COMMISSION_PAYOUT;
        }

        if ($invoice->type) {
            return $invoice->type;
        }

        if ($invoice->is_platform_fee) {
            return Invoice::INVOICE_TYPE_PLATFORM_FEE;
        }

        if ($this->looksLikeBorrowerFee($invoice)) {
            return Invoice::INVOICE_TYPE_BORROWER_FEE;
        }

        if ($firstCycle && $invoice->id === $firstCycle->id) {
            return $downPayment > 0
                ? Invoice::INVOICE_TYPE_DOWN_PAYMENT
                : Invoice::INVOICE_TYPE_ENROLLMENT;
        }

        return Invoice::INVOICE_TYPE_INSTALLMENT;
    }

    /**
     * A one-off charge that sits outside the billing cycle: it never becomes the
     * current cycle invoice, never counts toward installments, and is never expired
     * for being superseded.
     */
    protected function isOneOffInvoice(Invoice $invoice): bool
    {
        if ($invoice->is_platform_fee) {
            return false; // platform fee IS a cycle, just a later one
        }

        if (in_array($invoice->type, [
            Invoice::INVOICE_TYPE_BORROWER_FEE,
            Invoice::INVOICE_TYPE_USER_SEAT,
            Invoice::INVOICE_TYPE_COMMISSION_PAYOUT,
        ], true)) {
            return true;
        }

        return !$invoice->type && $this->looksLikeBorrowerFee($invoice);
    }

    /**
     * The one-off fee a non-host partner owes the host for each borrower they bring
     * on — the "Fixed Enrollment" borrower_fee plan.
     *
     * Current code addresses it to the PARTNER (EnrollmentService), but older rows
     * addressed the same charge to the Borrower, so entity_type alone is not enough.
     * is_partner_paying alone is not enough either: a borrower ENROLLMENT invoice
     * also carries that flag when the partner is paying on the borrower's behalf.
     * The reliable signal is the pair — partner-paid, for a borrower, at exactly the
     * borrower-fee plan's amount.
     */
    protected function looksLikeBorrowerFee(Invoice $invoice): bool
    {
        if (!$invoice->is_partner_paying) {
            return false;
        }

        if ($invoice->entity_type === Partner::class) {
            return true;
        }

        $feeAmount = $this->borrowerFeeAmount();

        return $feeAmount !== null
            && $invoice->borrower_id
            && abs((float) $invoice->payment_amount - $feeAmount) < 0.01;
    }

    /** The active borrower-fee plan amount, resolved once. */
    protected function borrowerFeeAmount(): ?float
    {
        if ($this->borrowerFeeAmount === false) {
            $plan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE)
                ->where('status', true)
                ->first();

            $this->borrowerFeeAmount = $plan ? (float) $plan->total_amount : null;
        }

        return $this->borrowerFeeAmount;
    }

    protected function applyInvoiceChanges(Invoice $invoice, array $changes, string $suffix = ''): void
    {
        $diff = $this->diff($invoice, $changes);

        if (!$diff) {
            return;
        }

        foreach ($diff as $column => [$from, $to]) {
            $this->line(sprintf(
                '     invoice #%-5d %-16s %-22s -> %s %s',
                $invoice->id,
                $column,
                $this->fmt($from),
                $this->fmt($to),
                $suffix
            ));
        }

        if ($this->dryRun) {
            // Keep the in-memory copy consistent so the rebuild reasons about
            // repaired values during a dry run too.
            $invoice->forceFill($changes);
        } else {
            $invoice->update($changes);
        }

        $this->invoicesRepaired++;
    }

    /**
     * Invoices that belong to no subscription at all still need their malformed type
     * normalised, so nothing is left holding a value the code cannot match on.
     */
    protected function normaliseUnlinkedInvoices(): void
    {
        $orphans = Invoice::whereNull('subscription_id')
            ->whereNotIn('id', array_keys($this->handledInvoiceIds) ?: [0])
            ->orderBy('id')
            ->get();

        if ($orphans->isEmpty()) {
            return;
        }

        $this->line('');
        $this->line(sprintf(' %d invoice(s) belong to no subscription:', $orphans->count()));

        foreach ($orphans as $invoice) {
            $normalised = $invoice->type
                ? str_replace(' ', '_', strtolower($invoice->type))
                : null;

            if ($normalised && $normalised !== $invoice->type) {
                $this->applyInvoiceChanges($invoice, ['type' => $normalised]);
                continue;
            }

            $this->line(sprintf(
                '     invoice #%-5d amount %-10s status %-9s type %s  (left unlinked)',
                $invoice->id,
                $invoice->payment_amount,
                $invoice->status,
                $invoice->type ?: 'NULL'
            ));
        }
    }

    // ------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------

    /**
     * Force every signed-in client to pull fresh session data.
     *
     * This repair rewrites phase, status, amounts and billing dates — all of which
     * the SPAs cache in their auth store from UserResource / UserDetailResource. Any
     * session open when this runs would keep showing pre-repair values until the
     * user signed out, so is_refresh is raised across the board to make the clients
     * re-fetch on their next request.
     */
    protected function flagUsersForRefresh(): void
    {
        $stale = User::where(function ($q) {
            $q->where('is_refresh', false)->orWhereNull('is_refresh');
        })->count();

        $this->line('');
        $this->line(sprintf(
            ' users flagged for refresh: %d of %d (is_refresh -> true)',
            $stale,
            User::count()
        ));

        if (!$this->dryRun) {
            User::query()->update(['is_refresh' => true]);
        }

        $this->usersFlagged = $stale;
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /**
     * Re-derive the denormalised columns on payment rows from their invoice, the way
     * UnifiedPaymentService writes them on a live charge.
     */
    protected function repairPaymentsFor($invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $payments = Payment::whereIn('invoice_id', $invoices->pluck('id'))->orderBy('id')->get();

        foreach ($payments as $payment) {
            $invoice = $invoices->firstWhere('id', $payment->invoice_id);

            if (!$invoice) {
                continue;
            }

            $isPartnerPaying = (bool) ($invoice->is_partner_paying
                || in_array($invoice->entity_type, [Partner::class, User::class, Employee::class], true));

            $changes = [
                'borrower_id'       => $invoice->borrower_id,
                'partner_id'        => $invoice->partner_id,
                'entity_type'       => $invoice->entity_type,
                'entity_id'         => $invoice->entity_id,
                'is_partner_paying' => $isPartnerPaying ? 1 : 0,
            ];

            $diff = $this->diff($payment, $changes);

            if (!$diff) {
                continue;
            }

            foreach ($diff as $column => [$from, $to]) {
                $this->line(sprintf(
                    '     payment #%-5d %-16s %-22s -> %s',
                    $payment->id,
                    $column,
                    $this->fmt($from),
                    $this->fmt($to)
                ));
            }

            if (!$this->dryRun) {
                $payment->update($changes);
            }

            $this->paymentsRepaired++;
        }
    }

    // ------------------------------------------------------------------
    // Reporting helpers
    // ------------------------------------------------------------------

    /**
     * Flag rows where the next charge date moves EARLIER — real customers get billed
     * sooner than the old data implied, so a human should sign that off.
     */
    protected function recordBillingImpact(Subscription $subscription, ?Carbon $newExpectedEnd): void
    {
        $old = $subscription->subscription_end_date
            ? Carbon::parse($subscription->subscription_end_date)
            : null;

        if (!$newExpectedEnd || !$old || !$newExpectedEnd->lt($old)) {
            return;
        }

        $this->billingImpact[] = sprintf(
            ' subscription #%-4d %-8s #%-4s next charge %s -> %s (%d days earlier)',
            $subscription->id,
            class_basename($subscription->entity_type),
            $subscription->entity_id,
            $old->toDateString(),
            $newExpectedEnd->toDateString(),
            $newExpectedEnd->diffInDays($old)
        );
    }

    protected function flagManual(Subscription $subscription, string $reason): void
    {
        $this->manualReview[] = sprintf(' subscription #%-4d %s', $subscription->id, $reason);
    }

    /**
     * Columns whose value would actually change, as column => [from, to]. Compared
     * loosely so 500 vs "500.00" is not reported as a change.
     */
    protected function diff($model, array $changes): array
    {
        $diff = [];

        foreach ($changes as $column => $new) {
            $old = $model->getAttribute($column);

            if ($this->normalise($old) === $this->normalise($new)) {
                continue;
            }

            $diff[$column] = [$old, $new];
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

        if (is_bool($value)) {
            return $value ? '1' : '0';
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

        if ($value === '') {
            return "''";
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value)->toDateString();
        }

        return (string) $value;
    }

    /** The date an invoice was actually settled. */
    protected function invoicePaidAt(Invoice $invoice)
    {
        return $invoice->payment_date ?? $invoice->updated_at;
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

        if ($this->billingImpact) {
            $this->line('');
            $this->line('--------------------------------------------------------------');
            $this->line(' BILLING IMPACT — these customers get charged sooner');
            $this->line('--------------------------------------------------------------');
            foreach ($this->billingImpact as $row) {
                $this->line($row);
            }
        }

        $this->line('');
        $this->line('--------------------------------------------------------------');
        $this->line(sprintf(' subscriptions repaired : %d', $this->subscriptionsRepaired));
        $this->line(sprintf(' subscriptions deleted  : %d (soft)', $this->subscriptionsDeleted));
        $this->line(sprintf(' subscriptions restored : %d (Fixed Enrollment -> One Time Payment)', $this->subscriptionsRestored));
        $this->line(sprintf(' plans remapped         : %d', $this->plansRemapped));
        $this->line(sprintf(' invoices repaired      : %d', $this->invoicesRepaired));
        $this->line(sprintf(' payments repaired      : %d', $this->paymentsRepaired));
        $this->line(sprintf(' users flagged refresh  : %d', $this->usersFlagged));
        $this->line('--------------------------------------------------------------');

        if ($this->dryRun) {
            $this->line(' DRY RUN — nothing was written.');
            $this->line(' Re-run with SEED_DRY_RUN=false to apply.');
        } else {
            $this->line(' Changes applied.');
        }

        $this->line('');
    }

    protected function line(string $message): void
    {
        if (isset($this->command)) {
            $this->command->getOutput()->writeln($message);
            return;
        }

        echo $message, PHP_EOL;
    }
}
