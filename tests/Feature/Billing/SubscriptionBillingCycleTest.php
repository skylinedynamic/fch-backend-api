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

    private function partner(bool $isHost, string $partnerType = Partner::PARTNER_TYPE_WHITE_LABEL): Partner
    {
        $suffix = ($isHost ? 'host' : 'reseller')
            . ($partnerType === Partner::PARTNER_TYPE_WHITE_LABEL ? '' : '-certified');

        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $partnerType,
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

    /**
     * The subscription's own enrollment / installment / platform-fee invoices.
     *
     * Spelled out rather than read from Invoice::CYCLE_INVOICE_TYPES on purpose:
     * a test that counts using the same constant the code under test consults
     * would keep passing if that constant were wrong.
     */
    private function cycleInvoices(Subscription $subscription)
    {
        return Invoice::where('subscription_id', $subscription->id)
            ->whereIn('type', [
                Invoice::INVOICE_TYPE_ENROLLMENT,
                Invoice::INVOICE_TYPE_DOWN_PAYMENT,
                Invoice::INVOICE_TYPE_INSTALLMENT,
                Invoice::INVOICE_TYPE_PLATFORM_FEE,
            ])
            ->get();
    }

    private function openInvoice(Subscription $subscription): ?Invoice
    {
        return $this->cycleInvoices($subscription)
            ->where('status', Invoice::STATUS_UNPAID)
            ->sortByDesc('id')
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
        $this->assertSame(today()->copy()->addMonths(12)->toDateString(), $subscription->subscription_end_date->toDateString());

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

    #[Test]
    public function a_partners_one_time_payment_covers_twelve_months_before_the_platform_fee_starts(): void
    {
        $reseller = $this->partner(isHost: false);
        $subscription = $this->enrol($reseller, $this->partnerOneTime);

        $this->payInvoice($this->openInvoice($subscription), $subscription);

        // Inside the 12 months: no platform fee, however often the cron runs.
        $this->runCron();
        $subscription->forceFill(['subscription_end_date' => today()->copy()->addDays(6)])->save();
        $this->runCron();

        $platformFees = fn() => Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_PLATFORM_FEE)
            ->count();

        $this->assertSame(0, $platformFees(), 'the one-time payment already covers these months');

        // Within generate_before_days of the end: the first platform fee is raised.
        // A day inside the window, not on its edge: SQLite stores the date with a
        // time and compares it as text, so the boundary day itself never matches.
        $subscription->forceFill(['subscription_end_date' => today()->copy()->addDays(4)])->save();
        $this->runCron();

        $this->assertSame(1, $platformFees());
    }

    #[Test]
    public function the_twelve_months_count_from_the_payment_date_even_over_an_existing_end_date(): void
    {
        $reseller = $this->partner(isHost: false);
        $subscription = $this->enrol($reseller, $this->partnerOneTime, [
            'subscription_end_date' => today()->copy()->addDays(20),
        ]);

        $this->payInvoice($this->openInvoice($subscription), $subscription);

        $this->assertSame(
            today()->copy()->addMonths(12)->toDateString(),
            $subscription->fresh()->subscription_end_date->toDateString()
        );
    }

    #[Test]
    public function a_certified_partner_gets_the_same_twelve_months(): void
    {
        $certifiedPlan = $this->plan([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'entity_type' => Partner::class,
            'total_amount' => 2997.00,
            'platform_fee' => 199.00,
            'total_installment_count_upto' => 1,
        ]);

        $certified = $this->partner(isHost: false, partnerType: Partner::PARTNER_TYPE_CERTIFIED);
        $subscription = $this->enrol($certified, $certifiedPlan);

        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $subscription->refresh();

        $this->assertSame(Subscription::PHASE_PLATFORM_FEE, $subscription->current_phase);
        $this->assertSame(today()->copy()->addMonths(12)->toDateString(), $subscription->subscription_end_date->toDateString());
    }

    #[Test]
    public function each_platform_fee_after_the_twelve_months_buys_one_month(): void
    {
        $reseller = $this->partner(isHost: false);
        $subscription = $this->enrol($reseller, $this->partnerOneTime);
        $this->payInvoice($this->openInvoice($subscription), $subscription);

        // Coverage ends in 4 days: the platform fee is raised and paid early.
        $coverageEnd = today()->copy()->addDays(4);
        $subscription->forceFill(['subscription_end_date' => $coverageEnd])->save();
        $this->runCron();

        $this->payInvoice($this->openInvoice($subscription->fresh()), $subscription);

        $this->assertSame(
            $coverageEnd->copy()->addMonth()->toDateString(),
            $subscription->fresh()->subscription_end_date->toDateString(),
            'a platform fee is one month, not twelve'
        );
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

    #[Test]
    public function the_open_invoice_guard_holds_even_when_the_query_filter_is_bypassed(): void
    {
        // generateDueInvoices() already excludes rows with an open cycle invoice in
        // SQL, so the cron never reaches the same check inside createInvoiceIfDue().
        // That check is the second line of defence for any other caller, and without
        // exercising it directly nothing would notice if it broke.
        $reseller = $this->partner(isHost: false);
        $subscription = $this->enrol($this->borrower($reseller, 'Direct'), $this->borrowerInstallment);

        $this->payInvoice($this->openInvoice($subscription), $subscription);
        $this->expire($subscription->fresh());

        // Due, and nothing open yet: it would bill.
        $this->assertTrue($this->callGuard($subscription->fresh()));

        // Now leave a cycle invoice open.
        $this->invoice($subscription->fresh(), Invoice::INVOICE_TYPE_INSTALLMENT);
        $this->assertFalse($this->callGuard($subscription->fresh()), 'an open cycle invoice must block the next one');

        // A borrower fee is the partner's debt and must not block anything.
        Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_INSTALLMENT)
            ->update(['status' => Invoice::STATUS_PAID]);
        $this->invoice($subscription->fresh(), Invoice::INVOICE_TYPE_BORROWER_FEE, 800.00);

        $this->assertTrue($this->callGuard($subscription->fresh()), 'a borrower fee must not block the cycle');
    }

    private function callGuard(Subscription $subscription): bool
    {
        $method = new \ReflectionMethod($this->service, 'createInvoiceIfDue');
        $method->setAccessible(true);

        return $method->invoke($this->service, $subscription);
    }

    // ── Invoice amounts ───────────────────────────────────────────────

    #[Test]
    public function an_installment_is_billed_at_the_agreed_monthly_figure_not_a_recomputed_one(): void
    {
        // The real numbers from a 7,696 partner plan: 1,000 down, 12 months, so the
        // remaining 6,696 is 558.00 a month. total_subscription_amount ALREADY has
        // the down payment removed, so recomputing the monthly figure by subtracting
        // it again billed 474.67 - the customer underpaid every cycle and the plan
        // could never settle.
        $reseller = $this->partner(isHost: false);

        $subscription = $this->enrol($reseller, $this->partnerOneTime, [
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 1,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.00,
            'total_subscription_amount' => 6696.00,
            'subscription_amount_per_month' => 558.00,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->copy()->subMonth(),
        ]);

        // The enrollment invoice enrol() opens would block the next cycle.
        Invoice::where('subscription_id', $subscription->id)->update(['status' => Invoice::STATUS_PAID]);

        $this->expire($subscription->fresh());
        $this->runCron();

        $raised = Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_INSTALLMENT)
            ->latest('id')
            ->first();

        $this->assertNotNull($raised, 'the due cycle should have been billed');
        $this->assertEquals(558.00, (float) $raised->payment_amount);
        $this->assertNotEquals(474.67, round((float) $raised->payment_amount, 2), 'the down payment must not be subtracted twice');
    }

    /**
     * BUG-126: after the down payment was paid the subscription still said the
     * down payment was due - installments_paid stays 0 (the down payment is not
     * one of the installments), and nextBilling() read that as "nothing paid".
     * What it shows must be what the next cycle actually bills.
     */
    #[Test]
    public function after_the_down_payment_the_next_amount_due_is_the_monthly_installment(): void
    {
        $reseller = $this->partner(isHost: false);

        $subscription = $this->enrol($reseller, $this->partnerOneTime, [
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.70,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
        ]);
        $downPayment = Invoice::where('subscription_id', $subscription->id)->firstOrFail();
        $downPayment->update(['type' => Invoice::INVOICE_TYPE_DOWN_PAYMENT, 'payment_amount' => 1000.00]);

        $this->assertSame(1000.0, $subscription->fresh()->nextBilling()['amount'], 'before any payment the down payment is due');

        $this->payInvoice($downPayment, $subscription);

        $next = $subscription->fresh()->nextBilling();
        $this->assertSame(__('subscription.next_billing.installment'), $next['type']);
        $this->assertSame(558.06, $next['amount']);

        // And the next cycle bills exactly that - no second down payment.
        $this->expire($subscription->fresh());
        $this->runCron();

        $raised = $this->openInvoice($subscription);
        $this->assertNotNull($raised, 'the due cycle should have been billed');
        $this->assertSame(Invoice::INVOICE_TYPE_INSTALLMENT, $raised->type);
        $this->assertEquals(558.06, (float) $raised->payment_amount);
    }

    #[Test]
    public function a_borrower_fee_does_not_make_the_first_cycle_look_already_billed(): void
    {
        // The down payment is billed on the FIRST cycle. That used to be decided by
        // counting every non-platform-fee invoice on the subscription, so a borrower
        // fee - the partner's separate charge - made the row look already billed and
        // the borrower was charged an installment instead of their down payment.
        $reseller = $this->partner(isHost: false);
        $borrower = $this->borrower($reseller, 'Downpayer');

        $subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'subscription_plan_id' => $this->borrowerInstallment->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 0,
            'down_payment' => 100.00,
            'payable_amount' => 3000.00,
            'total_subscription_amount' => 2900.00,
            'subscription_amount_per_month' => 241.67,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->copy()->subMonth(),
            'subscription_end_date' => today()->copy()->subDay(),
        ]);

        // The partner's fee for bringing this borrower on, already settled.
        $fee = $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, 800.00);
        $fee->update(['status' => Invoice::STATUS_PAID]);

        $this->runCron();

        $raised = Invoice::where('subscription_id', $subscription->id)
            ->whereIn('type', [Invoice::INVOICE_TYPE_DOWN_PAYMENT, Invoice::INVOICE_TYPE_INSTALLMENT])
            ->latest('id')
            ->first();

        $this->assertNotNull($raised);
        $this->assertEquals(100.00, (float) $raised->payment_amount, 'the first cycle bills the down payment');
    }

    #[Test]
    public function an_installment_row_with_no_monthly_figure_is_refused_rather_than_guessed(): void
    {
        // An installment plan always carries subscription_amount_per_month -
        // EnrollmentService writes it at signup.
        // A row without one is broken, so nothing is billed: deriving an amount
        // would invoice the customer a figure nobody agreed to, and would hide the
        // defect behind a plausible-looking invoice.
        $reseller = $this->partner(isHost: false);

        $subscription = $this->enrol($reseller, $this->partnerOneTime, [
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 1,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.00,
            'total_subscription_amount' => 6696.00,
            'subscription_amount_per_month' => null,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->copy()->subMonth(),
        ]);

        Invoice::where('subscription_id', $subscription->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->expire($subscription->fresh());
        $this->runCron();

        $this->assertNull(
            Invoice::where('subscription_id', $subscription->id)
                ->where('type', Invoice::INVOICE_TYPE_INSTALLMENT)
                ->first(),
            'a broken row must not be billed a derived amount'
        );
    }

    #[Test]
    public function each_phase_bills_from_its_own_column(): void
    {
        // Two different figures on one subscription, deliberately unequal so neither
        // assertion can pass by reading the wrong column:
        //   installment  -> subscription_amount_per_month (558.00)
        //   platform_fee -> monthly_platform_fee          (399.00)
        $reseller = $this->partner(isHost: false);

        $subscription = $this->enrol($reseller, $this->partnerOneTime, [
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 1,
            'down_payment' => 1000.00,
            'payable_amount' => 7696.00,
            'total_subscription_amount' => 6696.00,
            'subscription_amount_per_month' => 558.00,
            'monthly_platform_fee' => 399.00,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->copy()->subMonth(),
        ]);

        Invoice::where('subscription_id', $subscription->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->expire($subscription->fresh());
        $this->runCron();

        $installment = Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_INSTALLMENT)->latest('id')->first();

        $this->assertNotNull($installment, 'the installment cycle should have billed');
        $this->assertEquals(558.00, (float) $installment->payment_amount, 'installments come from subscription_amount_per_month');

        // Now the same subscription in its platform-fee phase.
        Invoice::where('subscription_id', $subscription->id)->update(['status' => Invoice::STATUS_PAID]);
        $subscription->forceFill(['current_phase' => Subscription::PHASE_PLATFORM_FEE])->save();
        $this->expire($subscription->fresh());
        $this->runCron();

        $platformFee = Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_PLATFORM_FEE)->latest('id')->first();

        $this->assertNotNull($platformFee, 'the platform-fee cycle should have billed');
        $this->assertEquals(399.00, (float) $platformFee->payment_amount, 'the platform fee comes from monthly_platform_fee');
        $this->assertNotEquals(558.00, (float) $platformFee->payment_amount, 'never the installment figure');
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
