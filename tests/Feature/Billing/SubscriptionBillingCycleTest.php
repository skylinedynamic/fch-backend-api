<?php

namespace Tests\Feature\Billing;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The billing engine end to end: real partners and borrowers, real payments, and
 * the real subscriptions:process-all command raising the invoices.
 *
 * The rules being proven:
 *   - one open cycle invoice at a time; nothing further is raised until it is paid
 *   - paying before the cycle date anchors on it, paying after anchors on today
 *   - a borrower who has paid in full carries NULL and is never billed again
 *   - a partner rolls into the platform fee and keeps billing forever
 *   - a borrower fee, which the PARTNER owes, never blocks the borrower's own cycle
 *   - nobody who is not Active gets billed or charged
 *
 * Auto-charge jobs are faked: this asserts which invoices the cron selects for
 * collection, without any contact with a payment gateway.
 */
class SubscriptionBillingCycleTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    private SubscriptionPlan $borrowerOneTime;
    private SubscriptionPlan $borrowerInstallment;
    private SubscriptionPlan $partnerOneTime;
    private SubscriptionPlan $borrowerFeePlan;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->service = app(SubscriptionService::class);

        $this->borrowerOneTime = $this->plan([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'entity_type' => Borrower::class,
            'total_amount' => 2497.00,
            'total_installment_count_upto' => 1,
        ]);

        $this->borrowerInstallment = $this->plan([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG,
            'entity_type' => Borrower::class,
            'total_amount' => 3000.00,
            'total_installment_count_upto' => 24,
        ]);

        $this->partnerOneTime = $this->plan([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'entity_type' => Partner::class,
            'total_amount' => 6997.00,
            'platform_fee' => 299.00,
            'total_installment_count_upto' => 1,
        ]);

        $this->borrowerFeePlan = $this->plan([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG,
            'total_amount' => 800.00,
        ]);
    }

    // ── The world ─────────────────────────────────────────────────────

    private function plan(array $attributes): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'partner_type' => null,
            'platform_fee' => null,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'status' => true,
        ], $attributes));
    }

    private function partner(bool $isHost): Partner
    {
        $suffix = $isHost ? 'host' : 'reseller';

        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => ucfirst($suffix) . ' Partner LLC',
            'full_name' => ucfirst($suffix) . ' Owner',
            'email' => $suffix . '@example.test',
        ]);
    }

    private function borrower(Partner $partner, string $name): Borrower
    {
        return Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => $name,
            'last_name' => 'Tester',
            'email' => strtolower($name) . '@example.test',
        ]);
    }

    /**
     * A subscription in the state the enrollment flow leaves it: created, nothing
     * paid, its signup invoice already open.
     */
    private function enrol($entity, SubscriptionPlan $plan, array $attributes = []): Subscription
    {
        $isInstallment = $plan->enrollment_type === SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT;

        $subscription = Subscription::create(array_merge([
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'subscription_plan_id' => $plan->id,
            'enrollment_type' => $plan->enrollment_type,
            'partner_type' => $plan->partner_type,
            'installment_type' => $isInstallment ? 1 : 0,
            'installment_count' => $isInstallment ? 3 : 1,
            'installments_paid' => 0,
            'down_payment' => 0,
            'payable_amount' => $plan->total_amount,
            'total_subscription_amount' => $plan->total_amount,
            'subscription_amount_per_month' => $isInstallment
                ? round($plan->total_amount / 3, 2)
                : $plan->total_amount,
            'monthly_platform_fee' => $entity instanceof Partner ? $plan->platform_fee : 0,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'status' => Subscription::STATUS_PENDING,
            'subscription_start_date' => null,
            'subscription_end_date' => null,
        ], $attributes));

        $invoice = $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT);
        $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

        return $subscription->fresh();
    }

    private function invoice(Subscription $subscription, string $type, ?float $amount = null): Invoice
    {
        $isBorrower = $subscription->entity_type === Borrower::class;
        $entity = $subscription->entity;

        return Invoice::create([
            'invoice_id' => 'INV-' . str_pad((string) (Invoice::count() + 1), 6, '0', STR_PAD_LEFT),
            'subscription_id' => $subscription->id,
            'partner_id' => $isBorrower ? $entity->partner_id : $entity->id,
            'borrower_id' => $isBorrower ? $entity->id : null,
            'entity_type' => $subscription->entity_type,
            'entity_id' => $subscription->entity_id,
            'payment_amount' => $amount ?? $subscription->subscription_amount_per_month,
            'month_count' => 1,
            'status' => Invoice::STATUS_UNPAID,
            'type' => $type,
            'currency' => 'USD',
        ]);
    }

    // ── Driving the system ────────────────────────────────────────────

    /** Settle an invoice the way a successful gateway payment does. */
    private function payInvoice(Invoice $invoice, Subscription $subscription): void
    {
        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'payment_date' => today()->toDateString(),
        ]);

        $this->service->handleSuccessfulPayment($invoice->fresh(), $subscription->fresh());
    }

    /** Run the real cron command. */
    private function runCron(): void
    {
        $this->artisan('subscriptions:process-all')->assertSuccessful();
    }

    private function cycleInvoices(Subscription $subscription)
    {
        return Invoice::where('subscription_id', $subscription->id)
            ->whereIn('type', Invoice::CYCLE_INVOICE_TYPES)
            ->get();
    }

    private function openInvoice(Subscription $subscription): ?Invoice
    {
        return Invoice::where('subscription_id', $subscription->id)
            ->where('status', Invoice::STATUS_UNPAID)
            ->whereIn('type', Invoice::CYCLE_INVOICE_TYPES)
            ->latest('id')
            ->first();
    }

    /** Make the next cycle fall due by moving the date into the past. */
    private function expire(Subscription $subscription, int $daysAgo = 1): void
    {
        $subscription->forceFill([
            'subscription_end_date' => today()->copy()->subDays($daysAgo),
        ])->save();
    }

    // ── One time payment ──────────────────────────────────────────────

    #[Test]
    public function a_host_partners_borrower_paying_one_time_is_finished_and_never_billed_again(): void
    {
        $host = $this->partner(isHost: true);
        $borrower = $this->borrower($host, 'Hosted');
        $subscription = $this->enrol($borrower, $this->borrowerOneTime);

        $this->payInvoice($this->openInvoice($subscription), $subscription);

        $subscription->refresh();
        $this->assertNull($subscription->subscription_end_date, 'a finished borrower carries no cycle date');
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase);
        $this->assertSame(1, (int) $subscription->installments_paid);

        // Nothing further, however many times the cron runs.
        $this->runCron();
        $this->runCron();

        $this->assertCount(1, $this->cycleInvoices($subscription), 'no invoice may be raised after paying in full');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_same_holds_for_a_non_host_partners_borrower(): void
    {
        $reseller = $this->partner(isHost: false);
        $borrower = $this->borrower($reseller, 'Resold');
        $subscription = $this->enrol($borrower, $this->borrowerOneTime);

        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $this->runCron();

        $subscription->refresh();
        $this->assertNull($subscription->subscription_end_date);
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase);
        $this->assertCount(1, $this->cycleInvoices($subscription));
    }

    // ── Installments ──────────────────────────────────────────────────

    #[Test]
    public function an_installment_borrower_is_billed_one_cycle_at_a_time_until_the_plan_is_paid_off(): void
    {
        $reseller = $this->partner(isHost: false);
        $borrower = $this->borrower($reseller, 'Installer');
        $subscription = $this->enrol($borrower, $this->borrowerInstallment);

        // 1st payment: the cycle date starts one month out.
        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $subscription->refresh();

        $this->assertSame(
            today()->copy()->addMonth()->toDateString(),
            $subscription->subscription_end_date->toDateString(),
            'the first payment buys a month'
        );
        $this->assertSame(Subscription::PHASE_INSTALLMENT, $subscription->current_phase);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);

        // Not due yet — the cron must leave it alone.
        $this->runCron();
        $this->assertCount(1, $this->cycleInvoices($subscription), 'nothing is raised before the cycle is due');

        // 2nd cycle falls due.
        $this->expire($subscription);
        $this->runCron();
        $this->assertCount(2, $this->cycleInvoices($subscription), 'the due cycle raises exactly one invoice');

        // THE RULE: while it is unpaid, no further invoice however often the cron runs.
        $this->runCron();
        $this->runCron();
        $this->assertCount(2, $this->cycleInvoices($subscription), 'a second invoice must never stack on an unpaid one');

        // Pay it: 2/3.
        $this->payInvoice($this->openInvoice($subscription->fresh()), $subscription);
        $subscription->refresh();
        $this->assertSame(2, (int) $subscription->installments_paid);
        $this->assertSame(Subscription::PHASE_INSTALLMENT, $subscription->current_phase);

        // Final cycle.
        $this->expire($subscription);
        $this->runCron();
        $this->assertCount(3, $this->cycleInvoices($subscription));

        $this->payInvoice($this->openInvoice($subscription->fresh()), $subscription);
        $subscription->refresh();

        $this->assertSame(3, (int) $subscription->installments_paid);
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase, 'installment -> completed');
        $this->assertNull($subscription->subscription_end_date, 'a paid-off borrower carries no cycle date');

        // Paid off: never billed again.
        $this->runCron();
        $this->runCron();
        $this->assertCount(3, $this->cycleInvoices($subscription));
    }

    #[Test]
    public function paying_before_the_cycle_date_keeps_the_billing_day_and_paying_late_restarts_from_today(): void
    {
        $reseller = $this->partner(isHost: false);
        $subscription = $this->enrol($this->borrower($reseller, 'Timing'), $this->borrowerInstallment);
        $this->payInvoice($this->openInvoice($subscription), $subscription);

        // Due in 5 days; pay now, i.e. EARLY. The date must advance from the due
        // date, not from today, or the billing day walks backwards every cycle.
        $dueDate = today()->copy()->addDays(5);
        $subscription->forceFill(['subscription_end_date' => $dueDate])->save();

        $this->payInvoice($this->invoice($subscription->fresh(), Invoice::INVOICE_TYPE_INSTALLMENT), $subscription);

        $this->assertSame(
            $dueDate->copy()->addMonth()->toDateString(),
            $subscription->fresh()->subscription_end_date->toDateString(),
            'an early payment advances from the cycle date'
        );

        // Now a LATE one: due 10 days ago, paid today.
        $subscription->forceFill([
            'subscription_end_date' => today()->copy()->subDays(10),
            'installments_paid' => 1,
        ])->save();

        $this->payInvoice($this->invoice($subscription->fresh(), Invoice::INVOICE_TYPE_INSTALLMENT), $subscription);

        $this->assertSame(
            today()->copy()->addMonth()->toDateString(),
            $subscription->fresh()->subscription_end_date->toDateString(),
            'a late payment advances from today, never from a date already past'
        );
    }

    // ── Partner ───────────────────────────────────────────────────────

    #[Test]
    public function a_partner_rolls_into_the_platform_fee_and_keeps_billing_forever(): void
    {
        $reseller = $this->partner(isHost: false);
        $subscription = $this->enrol($reseller, $this->partnerOneTime);

        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $subscription->refresh();

        $this->assertSame(Subscription::PHASE_PLATFORM_FEE, $subscription->current_phase);
        $this->assertNotNull($subscription->subscription_end_date, 'a partner never stops being billed');
        $this->assertSame(today()->copy()->addMonth()->toDateString(), $subscription->subscription_end_date->toDateString());

        // Each month: one platform-fee invoice, at the plan's fee.
        $this->expire($subscription);
        $this->runCron();

        $platformFees = Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_PLATFORM_FEE)
            ->get();

        $this->assertCount(1, $platformFees);
        $this->assertEquals(299.00, (float) $platformFees->first()->payment_amount);

        // Unpaid: nothing stacks on it.
        $this->runCron();
        $this->assertCount(1, Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_PLATFORM_FEE)->get());

        // Paid: the clock moves on, and the date is never cleared.
        $this->payInvoice($platformFees->first()->fresh(), $subscription);
        $subscription->refresh();

        $this->assertNotNull($subscription->subscription_end_date);
        $this->assertSame(Subscription::PHASE_PLATFORM_FEE, $subscription->current_phase);
    }

    // ── The borrower fee belongs to the partner ───────────────────────

    #[Test]
    public function an_unpaid_borrower_fee_does_not_stop_the_borrowers_own_billing(): void
    {
        $reseller = $this->partner(isHost: false);
        $borrower = $this->borrower($reseller, 'Feebound');
        $subscription = $this->enrol($borrower, $this->borrowerInstallment);

        $this->payInvoice($this->openInvoice($subscription), $subscription);

        // The fee a NON-HOST partner owes the host for this borrower. EnrollmentService
        // attaches it to the borrower's subscription for traceability, but it is the
        // partner's debt - it must not hold the borrower's own cycle hostage.
        $fee = $this->invoice($subscription->fresh(), Invoice::INVOICE_TYPE_BORROWER_FEE, 800.00);
        $this->assertSame(Invoice::STATUS_UNPAID, $fee->status);

        $this->expire($subscription->fresh());
        $this->runCron();

        $this->assertCount(
            2,
            $this->cycleInvoices($subscription),
            "the borrower's cycle must still bill while their partner's fee is outstanding"
        );
        $this->assertSame(Invoice::STATUS_UNPAID, $fee->fresh()->status, 'the fee is left alone, not expired');
    }

    // ── Nobody inactive gets billed ───────────────────────────────────

    #[Test]
    public function a_suspended_borrower_is_neither_billed_nor_charged(): void
    {
        $reseller = $this->partner(isHost: false);
        $borrower = $this->borrower($reseller, 'Suspended');
        $subscription = $this->enrol($borrower, $this->borrowerInstallment);

        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $this->expire($subscription->fresh());

        $borrower->update(['status' => Borrower::STATUS_SUSPENDED]);
        Queue::fake();
        $this->runCron();

        $this->assertCount(1, $this->cycleInvoices($subscription), 'a suspended borrower is not billed');
        Queue::assertNotPushed(ChargeSubscriptionInvoiceJob::class);

        // Reactivated: billing resumes with exactly one invoice, not a backlog.
        $borrower->update(['status' => Borrower::STATUS_ACTIVE]);
        $this->runCron();

        $this->assertCount(2, $this->cycleInvoices($subscription));
    }

    #[Test]
    public function a_borrower_whose_partner_is_suspended_is_not_billed(): void
    {
        $reseller = $this->partner(isHost: false);
        $borrower = $this->borrower($reseller, 'Orphaned');
        $subscription = $this->enrol($borrower, $this->borrowerInstallment);

        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $this->expire($subscription->fresh());

        // The borrower reaches the platform through their partner.
        $reseller->update(['status' => Partner::STATUS_SUSPENDED]);
        $this->runCron();

        $this->assertCount(1, $this->cycleInvoices($subscription));
    }

    // ── Collection ────────────────────────────────────────────────────

    #[Test]
    public function the_cron_queues_exactly_the_open_invoices_of_active_subscribers(): void
    {
        $reseller = $this->partner(isHost: false);

        $billable = $this->enrol($this->borrower($reseller, 'Billable'), $this->borrowerInstallment);
        $this->payInvoice($this->openInvoice($billable), $billable);
        $this->expire($billable->fresh());

        $suspendedBorrower = $this->borrower($reseller, 'Frozen');
        $frozen = $this->enrol($suspendedBorrower, $this->borrowerInstallment);
        $this->payInvoice($this->openInvoice($frozen), $frozen);
        $this->expire($frozen->fresh());
        $suspendedBorrower->update(['status' => Borrower::STATUS_SUSPENDED]);

        Queue::fake();
        $this->runCron();

        $queued = collect(Queue::pushed(ChargeSubscriptionInvoiceJob::class))
            ->map(fn($job) => $job->invoice->subscription_id)
            ->unique()
            ->values()
            ->all();

        $this->assertContains($billable->id, $queued, 'an active subscriber with a debt is queued for collection');
        $this->assertNotContains($frozen->id, $queued, 'a suspended subscriber is never auto-charged');
    }
}
