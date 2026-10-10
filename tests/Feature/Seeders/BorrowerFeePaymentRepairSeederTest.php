<?php

namespace Tests\Feature\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use Database\Seeders\BorrowerFeePaymentRepairSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paying a borrower fee used to be treated as the borrower paying their own
 * enrollment: the borrower's subscription was completed (or its installments
 * started) and their unpaid enrollment invoice was unlinked, so they could no
 * longer even pay it. This one-off seeder puts those subscriptions back to
 * Pending and records partner_payment_for_me for every paid fee.
 */
#[CoversClass(BorrowerFeePaymentRepairSeeder::class)]
class BorrowerFeePaymentRepairSeederTest extends TestCase
{
    use RefreshDatabase;

    private Partner $reseller;
    private int $invoiceSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);
    }

    private function borrower(): Borrower
    {
        return Borrower::create([
            'partner_id' => $this->reseller->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo' . uniqid() . '@example.test',
            'partner_payment_for_me' => false,
        ]);
    }

    /** The state the bug left behind: completed by the partner's fee alone. */
    private function wronglyCompleted(array $attributes = []): Subscription
    {
        $borrower = $this->borrower();

        return Subscription::create(array_merge([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 1,
            'down_payment' => 0,
            'payable_amount' => 2497.00,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_COMPLETED,
            'status' => Subscription::STATUS_ACTIVE,
            'subscription_start_date' => today()->copy()->subMonth(),
            'first_payment_date' => today()->copy()->subMonth(),
            'enrollment_completed_at' => today()->copy()->subMonth(),
            'subscription_end_date' => null,
            'current_cycle_invoice_id' => null,
        ], $attributes));
    }

    private function invoice(Subscription $subscription, string $type, string $status): Invoice
    {
        $isFee = $type === Invoice::INVOICE_TYPE_BORROWER_FEE;

        return Invoice::create([
            'invoice_id' => 'INV-' . str_pad((string) ++$this->invoiceSeq, 6, '0', STR_PAD_LEFT),
            'subscription_id' => $subscription->id,
            'partner_id' => $this->reseller->id,
            'borrower_id' => $subscription->entity_id,
            'entity_type' => $isFee ? Partner::class : Borrower::class,
            'entity_id' => $isFee ? $this->reseller->id : $subscription->entity_id,
            'payment_amount' => $isFee ? 800.00 : 2497.00,
            'status' => $status,
            'type' => $type,
            'is_partner_paying' => $isFee,
            'currency' => 'USD',
            'payment_date' => $status === Invoice::STATUS_PAID ? today()->copy()->subMonth()->toDateString() : null,
        ]);
    }

    private function runSeeder(bool $dryRun = false): void
    {
        $seeder = new BorrowerFeePaymentRepairSeeder();
        $seeder->dryRun = $dryRun;
        $seeder->run();
    }

    #[Test]
    public function a_borrower_completed_by_the_fee_alone_is_put_back_to_pending_with_their_invoice_payable(): void
    {
        $subscription = $this->wronglyCompleted();
        $enrollment = $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_UNPAID);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);

        $this->runSeeder();
        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PENDING, $subscription->status);
        $this->assertSame(Subscription::PHASE_ENROLLMENT, $subscription->current_phase);
        $this->assertSame(0, (int) $subscription->installments_paid);
        $this->assertNull($subscription->first_payment_date);
        $this->assertNull($subscription->subscription_start_date);
        $this->assertNull($subscription->enrollment_completed_at);
        $this->assertNull($subscription->subscription_end_date);
        $this->assertSame($enrollment->id, $subscription->current_cycle_invoice_id, 'they must be able to pay it');
    }

    #[Test]
    public function an_installment_borrower_without_a_down_payment_goes_back_to_the_installment_phase(): void
    {
        $subscription = $this->wronglyCompleted([
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'installment_type' => 1,
            'installment_count' => 6,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'enrollment_completed_at' => null,
            'subscription_end_date' => today()->copy(),
        ]);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_UNPAID);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);

        $this->runSeeder();
        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_PENDING, $subscription->status);
        $this->assertSame(Subscription::PHASE_INSTALLMENT, $subscription->current_phase);
        $this->assertSame(0, (int) $subscription->installments_paid);
        $this->assertNull($subscription->subscription_end_date);
    }

    #[Test]
    public function a_borrower_who_really_paid_is_left_alone(): void
    {
        $subscription = $this->wronglyCompleted();
        $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_PAID);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);

        $this->runSeeder();
        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase);
    }

    #[Test]
    public function a_borrower_still_pending_is_left_alone(): void
    {
        $subscription = $this->wronglyCompleted([
            'status' => Subscription::STATUS_PENDING,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'installments_paid' => 0,
            'subscription_start_date' => null,
            'first_payment_date' => null,
            'enrollment_completed_at' => null,
        ]);
        $enrollment = $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_UNPAID);
        $subscription->update(['current_cycle_invoice_id' => $enrollment->id]);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);
        $before = $subscription->fresh()->toArray();

        $this->runSeeder();

        $this->assertEquals($before, $subscription->fresh()->toArray());
    }

    #[Test]
    public function every_paid_fee_records_that_the_partner_paid(): void
    {
        $paid = $this->wronglyCompleted();
        $this->invoice($paid, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);

        $unpaid = $this->wronglyCompleted();
        $this->invoice($unpaid, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_UNPAID);

        $this->runSeeder();

        $this->assertTrue((bool) Borrower::find($paid->entity_id)->partner_payment_for_me);
        $this->assertFalse((bool) Borrower::find($unpaid->entity_id)->partner_payment_for_me);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $subscription = $this->wronglyCompleted();
        $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_UNPAID);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);
        $before = $subscription->fresh()->toArray();

        $this->runSeeder(dryRun: true);

        $this->assertEquals($before, $subscription->fresh()->toArray());
        $this->assertFalse((bool) Borrower::find($subscription->entity_id)->partner_payment_for_me);
    }

    #[Test]
    public function running_it_twice_changes_nothing_the_second_time(): void
    {
        $subscription = $this->wronglyCompleted();
        $this->invoice($subscription, Invoice::INVOICE_TYPE_ENROLLMENT, Invoice::STATUS_UNPAID);
        $this->invoice($subscription, Invoice::INVOICE_TYPE_BORROWER_FEE, Invoice::STATUS_PAID);

        $this->runSeeder();
        $afterFirst = $subscription->fresh()->toArray();

        $this->runSeeder();

        $this->assertEquals($afterFirst, $subscription->fresh()->toArray());
    }
}
