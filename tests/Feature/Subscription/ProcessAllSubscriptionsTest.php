<?php

namespace Tests\Feature\Subscription;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests for `subscriptions:process-all` (ProcessAllSubscriptions)
 * and ChargeSubscriptionInvoiceJob.
 *
 * These are real-Eloquent, no-HTTP tests: the command itself and
 * ChargeSubscriptionInvoiceJob::handle() are invoked directly/through Artisan,
 * with Mail::fake() and a mocked PaymentGatewayManager/driver standing in for
 * any Stripe call (the driver's chargeOffSession() is not part of
 * PaymentGatewayInterface, but Mockery allows stubbing it on an interface mock
 * regardless — see StripeCustomerServiceTest / UnifiedPaymentServiceTest for
 * the same pattern used elsewhere in this suite).
 */
class ProcessAllSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────
    // Command: STEP 2 dispatch behavior
    // ─────────────────────────────────────────────────────────────

    public function test_command_dispatches_charge_job_for_unpaid_invoice_on_active_subscription(): void
    {
        Queue::fake();

        $borrower = Borrower::factory()->create();
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create([
            // Far enough in the future that generateDueInvoices() (STEP 1) does not
            // touch this subscription — isolates the STEP 2 dispatch assertion.
            'subscription_end_date' => now()->addYear(),
        ]);
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $this->artisan('subscriptions:process-all')->assertExitCode(0);

        Queue::assertPushed(ChargeSubscriptionInvoiceJob::class, function ($job) use ($invoice) {
            return $job->invoice->id === $invoice->id;
        });
    }

    public function test_command_does_not_dispatch_charge_job_for_paid_invoices(): void
    {
        Queue::fake();

        $borrower = Borrower::factory()->create();
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create([
            'subscription_end_date' => now()->addYear(),
        ]);
        Invoice::factory()->forBorrower($borrower)->paid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $this->artisan('subscriptions:process-all')->assertExitCode(0);

        Queue::assertNotPushed(ChargeSubscriptionInvoiceJob::class);
    }

    public function test_command_does_not_dispatch_charge_job_for_invoice_on_inactive_subscription(): void
    {
        Queue::fake();

        $borrower = Borrower::factory()->create();
        $subscription = Subscription::factory()->forBorrower($borrower)->cancelled()->create([
            'subscription_end_date' => now()->addYear(),
        ]);
        Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $this->artisan('subscriptions:process-all')->assertExitCode(0);

        Queue::assertNotPushed(ChargeSubscriptionInvoiceJob::class);
    }

    // ─────────────────────────────────────────────────────────────
    // ChargeSubscriptionInvoiceJob::handle()
    // ─────────────────────────────────────────────────────────────

    protected function fakeSubscriptionService(): SubscriptionService
    {
        $mock = Mockery::mock(SubscriptionService::class);
        $mock->shouldReceive('handleSuccessfulPayment')->zeroOrMoreTimes()->andReturn(null);

        return $mock;
    }

    public function test_job_charges_off_session_and_marks_invoice_paid_on_success(): void
    {
        $borrower = Borrower::factory()->create([
            'stripe_customer_id' => ['customer_id' => 'cus_test_off_session'],
        ]);
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
            'payment_amount' => 75.00,
        ]);

        $driver = Mockery::mock(PaymentGatewayInterface::class);
        $driver->shouldReceive('chargeOffSession')
            ->once()
            ->with('cus_test_off_session', 7500, 'usd', Mockery::type('string'))
            ->andReturn([
                'success' => true,
                'transaction_id' => 'pi_off_session_1',
                'status' => 'succeeded',
                'raw' => ['id' => 'pi_off_session_1'],
            ]);

        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldReceive('resolvePartnerProvider')->once()->andReturn(
            PartnerPaymentProvider::factory()->create(['partner_id' => $borrower->partner_id])
        );
        $gatewayManager->shouldReceive('driverFor')->once()->andReturn($driver);

        $job = new ChargeSubscriptionInvoiceJob($invoice);
        $job->handle($gatewayManager, $this->fakeSubscriptionService());

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'transaction_id' => 'pi_off_session_1',
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    public function test_job_records_failed_payment_and_leaves_invoice_unpaid_when_charge_declined(): void
    {
        $borrower = Borrower::factory()->create([
            'stripe_customer_id' => ['customer_id' => 'cus_test_declined'],
        ]);
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $driver = Mockery::mock(PaymentGatewayInterface::class);
        $driver->shouldReceive('chargeOffSession')->once()->andReturn([
            'success' => false,
            'status' => 'requires_payment_method',
            'raw' => ['status' => 'requires_payment_method'],
        ]);

        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldReceive('resolvePartnerProvider')->once()->andReturn(
            PartnerPaymentProvider::factory()->create(['partner_id' => $borrower->partner_id])
        );
        $gatewayManager->shouldReceive('driverFor')->once()->andReturn($driver);

        $job = new ChargeSubscriptionInvoiceJob($invoice);
        $job->handle($gatewayManager, $this->fakeSubscriptionService());

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => Payment::STATUS_FAILED,
        ]);
    }

    public function test_job_fails_cleanly_with_no_charge_attempted_when_stripe_customer_id_missing(): void
    {
        $borrower = Borrower::factory()->create([
            'stripe_customer_id' => null,
        ]);
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);

        // No expectations set on the gateway manager: if the job attempted to
        // resolve/charge anyway, Mockery would raise a BadMethodCallException
        // and fail this test.
        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldNotReceive('resolvePartnerProvider');
        $gatewayManager->shouldNotReceive('driverFor');

        $job = new ChargeSubscriptionInvoiceJob($invoice);
        $job->handle($gatewayManager, $this->fakeSubscriptionService());

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => Payment::STATUS_FAILED,
        ]);
    }

    public function test_job_is_a_no_op_when_invoice_already_paid(): void
    {
        $borrower = Borrower::factory()->create([
            'stripe_customer_id' => ['customer_id' => 'cus_already_paid'],
        ]);
        $subscription = Subscription::factory()->forBorrower($borrower)->active()->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create([
            'subscription_id' => $subscription->id,
        ]);

        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldNotReceive('resolvePartnerProvider');

        $job = new ChargeSubscriptionInvoiceJob($invoice);
        $job->handle($gatewayManager, $this->fakeSubscriptionService());

        $this->assertDatabaseCount('payments', 0);
    }
}
