<?php

namespace Tests\Feature\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use Database\Seeders\OneTimePaymentCoverageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A partner's One Time Payment now covers 12 months from its payment date, where
 * it used to cover one. This one-off seeder moves existing partner rows onto the
 * new rule - removing the platform fees billed inside those 12 months - and
 * settles borrower rows (no cycle date, phase completed).
 */
#[CoversClass(OneTimePaymentCoverageSeeder::class)]
class OneTimePaymentCoverageSeederTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private Carbon $paidOn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Reseller LLC',
            'full_name' => 'Reseller Owner',
            'email' => 'reseller@example.test',
        ]);

        $this->paidOn = today()->copy()->subMonths(3);
    }

    // ── The world ─────────────────────────────────────────────────────

    /** A partner whose One Time Payment was paid on $paidOn, under the old 1-month rule. */
    private function partnerSubscription(array $attributes = []): Subscription
    {
        $subscription = Subscription::create(array_merge([
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 1,
            'payable_amount' => 6997.00,
            'monthly_platform_fee' => 299.00,
            'current_phase' => Subscription::PHASE_PLATFORM_FEE,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => $this->paidOn,
            'first_payment_date' => $this->paidOn,
            'subscription_end_date' => $this->paidOn->copy()->addMonths(3),
        ], $attributes));

        $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_PAID, $this->paidOn);

        return $subscription;
    }

    private function borrowerSubscription(array $attributes = []): Subscription
    {
        $borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo@example.test',
        ]);

        $subscription = Subscription::create(array_merge([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 1,
            'payable_amount' => 2497.00,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_PLATFORM_FEE,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => $this->paidOn,
            'first_payment_date' => $this->paidOn,
            'subscription_end_date' => $this->paidOn->copy()->addMonth(),
        ], $attributes));

        $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_PAID, $this->paidOn);

        return $subscription;
    }

    private function invoice(Subscription $subscription, string $type, string $status, Carbon $raisedOn): Invoice
    {
        $isBorrower = $subscription->entity_type === Borrower::class;
        $isPlatformFee = $type === Invoice::INVOICE_TYPE_PLATFORM_FEE;

        $invoice = Invoice::create([
            'invoice_id' => 'INV-' . str_pad((string) (Invoice::withTrashed()->count() + 1), 6, '0', STR_PAD_LEFT),
            'subscription_id' => $subscription->id,
            'partner_id' => $this->partner->id,
            'borrower_id' => $isBorrower ? $subscription->entity_id : null,
            'entity_type' => $subscription->entity_type,
            'entity_id' => $subscription->entity_id,
            'payment_amount' => $isPlatformFee ? 299.00 : $subscription->payable_amount,
            'month_count' => 1,
            'status' => $status,
            'type' => $type,
            'is_platform_fee' => $isPlatformFee ? 1 : 0,
            'currency' => 'USD',
            'payment_date' => $status === Invoice::STATUS_PAID ? $raisedOn->toDateString() : null,
        ]);

        $invoice->forceFill(['created_at' => $raisedOn])->save();

        return $invoice;
    }

    private function payment(Invoice $invoice): Payment
    {
        return Payment::create([
            'partner_id' => $this->partner->id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'amount' => $invoice->payment_amount,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    private function runSeeder(bool $dryRun = false): void
    {
        $seeder = new OneTimePaymentCoverageSeeder();
        $seeder->dryRun = $dryRun;
        $seeder->run();
    }

    // ── Partners ──────────────────────────────────────────────────────

    #[Test]
    public function a_partners_end_date_becomes_twelve_months_from_the_payment_date(): void
    {
        $subscription = $this->partnerSubscription();

        $this->runSeeder();
        $subscription->refresh();

        $this->assertSame(
            $this->paidOn->copy()->addMonths(12)->toDateString(),
            $subscription->subscription_end_date->toDateString()
        );
        $this->assertSame(Subscription::PHASE_PLATFORM_FEE, $subscription->current_phase);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->current_cycle_invoice_id);
    }

    #[Test]
    public function platform_fees_inside_the_twelve_months_are_force_deleted_with_their_payments(): void
    {
        $subscription = $this->partnerSubscription();

        $paid = $this->invoice($subscription, Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::STATUS_PAID, $this->paidOn->copy()->addMonth());
        $paidPayment = $this->payment($paid);
        $unpaid = $this->invoice($subscription, Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::STATUS_UNPAID, $this->paidOn->copy()->addMonths(2));
        $subscription->update(['current_cycle_invoice_id' => $unpaid->id]);

        $this->runSeeder();

        $this->assertNull(Invoice::withTrashed()->find($paid->id), 'gone, not soft-deleted');
        $this->assertNull(Payment::withTrashed()->find($paidPayment->id), 'its payment goes with it');
        $this->assertNull(Invoice::withTrashed()->find($unpaid->id));
        $this->assertNull($subscription->fresh()->current_cycle_invoice_id);
    }

    #[Test]
    public function the_enrollment_invoice_and_its_payment_are_kept(): void
    {
        $subscription = $this->partnerSubscription();
        $enrollment = Invoice::where('subscription_id', $subscription->id)
            ->where('type', Invoice::INVOICE_TYPE_ENROLLMENT)
            ->first();
        $payment = $this->payment($enrollment);

        $this->runSeeder();

        $this->assertNotNull(Invoice::find($enrollment->id));
        $this->assertNotNull(Payment::find($payment->id));
    }

    #[Test]
    public function a_platform_fee_raised_for_the_cycle_after_the_twelve_months_is_kept(): void
    {
        // Paid a year ago: coverage ends in 4 days, so the cron has rightly raised
        // the first platform fee already.
        $this->paidOn = today()->copy()->subMonths(12)->addDays(4);
        $subscription = $this->partnerSubscription();

        $first = $this->invoice($subscription, Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::STATUS_UNPAID, today()->copy());

        $this->runSeeder();

        $this->assertNotNull(Invoice::find($first->id));
    }

    #[Test]
    public function pending_rows_are_not_touched(): void
    {
        $pendingSubscription = $this->partnerSubscription([
            'status' => Subscription::STATUS_PENDING,
            'subscription_end_date' => null,
        ]);

        $active = $this->partnerSubscription();
        $pendingInvoice = $this->invoice($active, Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::STATUS_PENDING, $this->paidOn->copy()->addMonth());

        $this->runSeeder();

        $this->assertNull($pendingSubscription->fresh()->subscription_end_date);
        $this->assertSame(Subscription::STATUS_PENDING, $pendingSubscription->fresh()->status);
        $this->assertNotNull(Invoice::find($pendingInvoice->id), 'a Pending invoice is left alone');
    }

    #[Test]
    public function an_end_date_already_past_the_twelve_months_is_never_moved_back(): void
    {
        $later = $this->paidOn->copy()->addMonths(14);
        $subscription = $this->partnerSubscription(['subscription_end_date' => $later]);

        $this->runSeeder();

        $this->assertSame($later->toDateString(), $subscription->fresh()->subscription_end_date->toDateString());
    }

    // ── Borrowers ─────────────────────────────────────────────────────

    #[Test]
    public function a_paid_borrower_is_completed_with_no_end_date(): void
    {
        $subscription = $this->borrowerSubscription();

        $this->runSeeder();
        $subscription->refresh();

        $this->assertNull($subscription->subscription_end_date);
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    // ── Running it ────────────────────────────────────────────────────

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $subscription = $this->partnerSubscription();
        $fee = $this->invoice($subscription, Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::STATUS_PAID, $this->paidOn->copy()->addMonth());
        $before = $subscription->subscription_end_date->toDateString();

        $this->runSeeder(dryRun: true);

        $this->assertSame($before, $subscription->fresh()->subscription_end_date->toDateString());
        $this->assertNotNull(Invoice::find($fee->id));
    }

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $subscription = $this->partnerSubscription();
        $this->invoice($subscription, Invoice::INVOICE_TYPE_PLATFORM_FEE, Invoice::STATUS_PAID, $this->paidOn->copy()->addMonth());

        $this->runSeeder();
        $afterFirst = $subscription->fresh()->toArray();
        $invoicesAfterFirst = Invoice::withTrashed()->pluck('id')->all();

        $this->runSeeder();

        $this->assertEquals($afterFirst, $subscription->fresh()->toArray());
        $this->assertEquals($invoicesAfterFirst, Invoice::withTrashed()->pluck('id')->all());
    }
}
