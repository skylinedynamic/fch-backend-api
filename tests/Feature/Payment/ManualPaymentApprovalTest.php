<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A manual payment is submitted as Processing and only counts once an admin
 * approves it. Approval is the moment it is paid, so that is the date the invoice
 * has to carry - and the date the subscription's cycle is counted from.
 */
#[CoversClass(PaymentService::class)]
class ManualPaymentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Partner $reseller;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $host = Partner::create([
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
            'full_name' => 'Reseller Owner',
            'email' => 'reseller@example.test',
        ]);

        $this->hostAdmin = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Host Admin',
            'email' => 'admin@host.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $host->id,
            'partner_id' => $host->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /** A partner's One Time Payment enrollment, paid manually and awaiting approval. */
    private function submittedOneTimePayment(): array
    {
        $subscription = Subscription::create([
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 6997.00,
            'monthly_platform_fee' => 299.00,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $invoice = Invoice::create([
            'invoice_id' => 'INV-000001',
            'subscription_id' => $subscription->id,
            'partner_id' => $this->reseller->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'payment_amount' => 6997.00,
            'month_count' => 1,
            'status' => Invoice::STATUS_PROCESSING,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'currency' => 'USD',
            'payment_date' => null,
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'partner_id' => $this->reseller->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'amount' => 6997.00,
            'currency' => 'USD',
            'transaction_id' => 'MANUAL_TX_1',
            'payment_from' => 'Bank Transfer',
            'is_manual' => true,
            'status' => Payment::STATUS_PROCESSING,
        ]);

        return [$subscription, $invoice, $payment];
    }

    private function approve(Payment $payment): array
    {
        $this->actingAs($this->hostAdmin);

        return app(PaymentService::class)->approvePayment($payment->id);
    }

    #[Test]
    public function approving_a_manual_payment_records_the_approval_day_as_the_payment_date(): void
    {
        [, $invoice, $payment] = $this->submittedOneTimePayment();

        $result = $this->approve($payment);

        $this->assertTrue($result['status'], $result['message'] ?? '');

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->payment_date, 'an approved invoice must carry its payment date');
        $this->assertSame(today()->toDateString(), $invoice->payment_date->toDateString());
    }

    #[Test]
    public function the_payment_date_matches_the_subscriptions_first_payment_date_and_starts_the_twelve_months(): void
    {
        [$subscription, $invoice, $payment] = $this->submittedOneTimePayment();

        $this->approve($payment);

        $subscription->refresh();
        $this->assertSame(
            $invoice->fresh()->payment_date->toDateString(),
            $subscription->first_payment_date->toDateString()
        );
        $this->assertSame(
            today()->copy()->addMonths(Subscription::ONE_TIME_PAYMENT_COVERAGE_MONTHS)->toDateString(),
            $subscription->subscription_end_date->toDateString()
        );
    }
}
