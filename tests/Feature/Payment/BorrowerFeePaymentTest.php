<?php

namespace Tests\Feature\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\Payment\PaymentService;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower fee is what a non-host partner owes the host for each borrower it
 * brings on. EnrollmentService links that invoice to the BORROWER's subscription
 * for traceability, but it is the partner's debt: paying it records that the
 * partner has paid (partner_payment_for_me) and nothing else. The borrower's own
 * subscription stays Pending until the borrower pays their own enrollment.
 */
#[CoversClass(SubscriptionService::class)]
class BorrowerFeePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Partner $reseller;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Host LLC',
            'email' => 'host@example.test',
        ]);

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->reseller->id,
            'status' => Borrower::STATUS_ONBOARDING,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo@example.test',
            'partner_payment_for_me' => false,
        ]);
    }

    // ── The world ─────────────────────────────────────────────────────

    /** A borrower freshly enrolled by a non-host partner: nothing paid by anyone. */
    private function enrol(bool $installment = false): array
    {
        $subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'enrollment_type' => $installment
                ? SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT
                : SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => $installment ? 1 : 0,
            'installment_count' => $installment ? 6 : 1,
            'installments_paid' => 0,
            'payable_amount' => 2497.00,
            'subscription_amount_per_month' => $installment ? 416.17 : null,
            'monthly_platform_fee' => 0,
            'current_phase' => $installment ? Subscription::PHASE_INSTALLMENT : Subscription::PHASE_ENROLLMENT,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $enrollment = Invoice::create([
            'invoice_id' => 'INV-000001',
            'subscription_id' => $subscription->id,
            'partner_id' => $this->reseller->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'payment_amount' => $installment ? 416.17 : 2497.00,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'currency' => 'USD',
        ]);

        $subscription->update(['current_cycle_invoice_id' => $enrollment->id]);

        $fee = Invoice::create([
            'invoice_id' => 'INV-000002',
            'subscription_id' => $subscription->id,
            'partner_id' => $this->reseller->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'payment_amount' => 800.00,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
            'currency' => 'USD',
        ]);

        return [$subscription->fresh(), $enrollment, $fee];
    }

    /** The partner pays the fee manually and the host approves it - the real flow. */
    private function approveManualFee(Invoice $fee): void
    {
        $fee->update(['status' => Invoice::STATUS_PROCESSING]);

        $payment = Payment::create([
            'invoice_id' => $fee->id,
            'partner_id' => $this->reseller->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'amount' => 800.00,
            'currency' => 'USD',
            'transaction_id' => 'MANUAL_TX_FEE',
            'is_manual' => true,
            'status' => Payment::STATUS_PROCESSING,
        ]);

        $hostAdmin = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Host Admin',
            'email' => 'admin@host.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'partner_id' => $this->host->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->actingAs($hostAdmin);
        $result = app(PaymentService::class)->approvePayment($payment->id);

        $this->assertTrue($result['status'], $result['message'] ?? '');
    }

    private function assertUntouched(Subscription $before, Subscription $after): void
    {
        $this->assertSame(Subscription::STATUS_PENDING, $after->status, 'the borrower has not paid');
        $this->assertSame($before->current_phase, $after->current_phase);
        $this->assertSame(0, (int) $after->installments_paid);
        $this->assertNull($after->first_payment_date);
        $this->assertNull($after->subscription_start_date);
        $this->assertNull($after->subscription_end_date);
        $this->assertNull($after->enrollment_completed_at);
        $this->assertSame($before->current_cycle_invoice_id, $after->current_cycle_invoice_id, 'their enrollment invoice is still the one to pay');
    }

    // ── The fee ───────────────────────────────────────────────────────

    #[Test]
    public function an_approved_fee_records_that_the_partner_paid_and_leaves_a_one_time_borrower_pending(): void
    {
        [$subscription, , $fee] = $this->enrol();

        $this->approveManualFee($fee);

        $this->assertSame(Invoice::STATUS_PAID, $fee->fresh()->status);
        $this->assertTrue((bool) $this->borrower->fresh()->partner_payment_for_me);
        $this->assertSame(Borrower::STATUS_ONBOARDING, $this->borrower->fresh()->status, 'the borrower is not activated by the partner paying');
        $this->assertUntouched($subscription, $subscription->fresh());
    }

    #[Test]
    public function an_approved_fee_leaves_an_installment_borrower_pending_with_nothing_counted(): void
    {
        [$subscription, , $fee] = $this->enrol(installment: true);

        $this->approveManualFee($fee);

        $this->assertTrue((bool) $this->borrower->fresh()->partner_payment_for_me);
        $this->assertUntouched($subscription, $subscription->fresh());
    }

    #[Test]
    public function a_fee_paid_by_card_is_handled_the_same_way(): void
    {
        // processPayment() hands a card-paid fee straight to handleSuccessfulPayment().
        [$subscription, , $fee] = $this->enrol();
        $fee->update(['status' => Invoice::STATUS_PAID, 'payment_date' => today()]);

        app(SubscriptionService::class)->handleSuccessfulPayment($fee->fresh(), $subscription);

        $this->assertTrue((bool) $this->borrower->fresh()->partner_payment_for_me);
        $this->assertUntouched($subscription, $subscription->fresh());
    }

    #[Test]
    public function the_borrower_paying_their_own_enrollment_afterwards_completes_them_as_usual(): void
    {
        [$subscription, $enrollment, $fee] = $this->enrol();
        $this->approveManualFee($fee);

        $enrollment->update(['status' => Invoice::STATUS_PAID, 'payment_date' => today()]);
        app(SubscriptionService::class)->handleSuccessfulPayment($enrollment->fresh(), $subscription->fresh());

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(Subscription::PHASE_COMPLETED, $subscription->current_phase);
        $this->assertSame(1, (int) $subscription->installments_paid);
        $this->assertSame(Borrower::STATUS_ACTIVE, $this->borrower->fresh()->status);
    }
}
