<?php

namespace Tests\Unit\Services\GlobalPayment;

use App\Contracts\Payment\PaymentGatewayInterface;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Subscription\SubscriptionService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for UnifiedPaymentService::processPayment() and the auto-charge
 * orchestration around it.
 *
 * TESTABILITY GAP (documented per task instructions, not fixed here):
 * chargePartnerCardAutomatically() (called indirectly via
 * triggerPartnerAutoChargeForBorrowerInvoice() -> chargePlatformFee()) calls
 * Stripe\PaymentIntent::create() and Payment::configureStripeForPartner() calls
 * Stripe::setApiKey() directly — bypassing the PaymentGatewayManager /
 * PaymentGatewayInterface seam entirely. These are not unit-testable without a
 * production refactor. We only exercise the branch of
 * triggerPartnerAutoChargeForBorrowerInvoice() that returns before any Stripe
 * call is made (no saved card on file), which is a real, reachable code path
 * and does not require a live/mocked Stripe SDK call.
 */
class UnifiedPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See PaymentGatewayManagerTest for the full explanation of this gap.
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

    /**
     * Build a UnifiedPaymentService with a mocked gateway manager (and optionally
     * a mocked SubscriptionService), leaving Payment ledger writes and mail real.
     */
    protected function makeService(
        ?PaymentGatewayManager $gatewayManager = null,
        ?SubscriptionService $subscriptionService = null
    ): UnifiedPaymentService {
        return new UnifiedPaymentService(
            // Default to a REAL PaymentGatewayManager: it only touches the DB
            // (resolvePartnerProvider) unless a driver's charge() is actually
            // invoked, which the manual/bank-transfer path never does. Tests
            // exercising the Stripe-via-driver path pass an explicit mock.
            $gatewayManager ?? new PaymentGatewayManager,
            null,
            $subscriptionService ?? $this->fakeSubscriptionService(),
            null
        );
    }

    /**
     * A SubscriptionService mock that simply accepts handleSuccessfulPayment() calls.
     */
    protected function fakeSubscriptionService()
    {
        $mock = Mockery::mock(SubscriptionService::class);
        $mock->shouldReceive('handleSuccessfulPayment')->zeroOrMoreTimes()->andReturn(null);

        return $mock;
    }

    protected function actingAsBorrowerFor(Borrower $borrower): User
    {
        $user = User::factory()->borrower($borrower)->create();
        $this->actingAs($user);

        return $user;
    }

    // ─────────────────────────────────────────────────────────────
    // Authorization / state guards
    // ─────────────────────────────────────────────────────────────

    public function test_process_payment_throws_when_invoice_is_not_unpaid(): void
    {
        $borrower = Borrower::factory()->create();
        $this->actingAsBorrowerFor($borrower);

        $invoice = Invoice::factory()->forBorrower($borrower)->paid()->create();

        $service = $this->makeService();

        $this->expectException(\Exception::class);

        $service->processPayment([
            'invoice_id' => $invoice->id,
            'payment_token' => json_encode(['mode' => 'manual']),
        ]);
    }

    public function test_process_payment_throws_when_caller_is_not_authorized_for_invoice(): void
    {
        $borrower = Borrower::factory()->create();
        $this->actingAsBorrowerFor($borrower);

        // Invoice belongs to a *different* borrower.
        $otherBorrower = Borrower::factory()->create();
        $invoice = Invoice::factory()->forBorrower($otherBorrower)->unpaid()->create();

        $service = $this->makeService();

        $this->expectException(\Exception::class);

        $service->processPayment([
            'invoice_id' => $invoice->id,
            'payment_token' => json_encode(['mode' => 'manual']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Manual / bank-transfer path (mockable seam)
    // ─────────────────────────────────────────────────────────────

    public function test_process_payment_manual_bank_transfer_creates_processing_payment_and_invoice(): void
    {
        $borrower = Borrower::factory()->create();
        $user = $this->actingAsBorrowerFor($borrower);

        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $bankProvider->id,
        ]);

        $bankDetail = PaymentMethodBankDetail::factory()->create([
            'partner_id' => $borrower->partner_id,
        ]);

        $subscription = Subscription::factory()->forBorrower($borrower)->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
            'payment_amount' => 150.00,
        ]);
        $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

        $service = $this->makeService();

        $result = $service->processPayment([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'bank_detail_id' => $bankDetail->id,
            'payment_token' => json_encode([
                'mode' => 'manual',
                'gateway' => PaymentProvider::BANK_TRANSFER,
                'bank_detail_id' => $bankDetail->id,
                'transaction_number' => 'REF123456',
            ]),
        ]);

        $this->assertTrue($result['success']);

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->status);
        $this->assertTrue((bool) $payment->is_manual);
        $this->assertSame('REF123456', $payment->transaction_id);
        $this->assertEquals(150.00, (float) $payment->amount);

        $this->assertSame(Invoice::STATUS_PROCESSING, $invoice->fresh()->status);
    }

    public function test_process_payment_manual_bank_transfer_requires_bank_detail(): void
    {
        $borrower = Borrower::factory()->create();
        $this->actingAsBorrowerFor($borrower);

        $bankProvider = PaymentProvider::factory()->bankTransfer()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $bankProvider->id,
        ]);

        $subscription = Subscription::factory()->forBorrower($borrower)->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);
        $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

        $service = $this->makeService();

        $this->expectException(\Exception::class);

        $service->processPayment([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'payment_token' => json_encode([
                'mode' => 'manual',
                'gateway' => PaymentProvider::BANK_TRANSFER,
                // no bank_detail_id supplied
            ]),
        ]);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => Invoice::STATUS_UNPAID]);
    }

    // ─────────────────────────────────────────────────────────────
    // Stripe-via-driver path (mocked PaymentGatewayInterface)
    // ─────────────────────────────────────────────────────────────

    public function test_process_payment_stripe_success_marks_invoice_paid_and_activates_borrower(): void
    {
        $borrower = Borrower::factory()->create(['status' => Borrower::STATUS_SUSPENDED]);
        $this->actingAsBorrowerFor($borrower);

        $stripeProvider = PaymentProvider::factory()->stripe()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $stripeProvider->id,
        ]);

        $subscription = Subscription::factory()->forBorrower($borrower)->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
            'payment_amount' => 250.00,
        ]);
        $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

        $driver = Mockery::mock(PaymentGatewayInterface::class);
        $driver->shouldReceive('charge')
            ->once()
            ->andReturn([
                'success' => true,
                'transaction_id' => 'pi_test_charge_1',
                'customer_id' => 'cus_test_1',
                'raw' => ['customer' => 'cus_test_1'],
            ]);

        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldReceive('resolvePartnerProvider')
            ->once()
            ->andReturn($partnerProvider->fresh(['provider']));
        $gatewayManager->shouldReceive('driverFor')->once()->andReturn($driver);

        $service = $this->makeService($gatewayManager);

        $result = $service->processPayment([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $partnerProvider->payment_provider_id,
            'payment_token' => 'pm_test_token',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $result['status']);

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertFalse((bool) $payment->is_manual);
        $this->assertSame('pi_test_charge_1', $payment->transaction_id);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Borrower::STATUS_ACTIVE, $borrower->fresh()->status);
    }

    public function test_process_payment_stripe_failure_throws_and_rolls_back_without_creating_payment(): void
    {
        $borrower = Borrower::factory()->create();
        $this->actingAsBorrowerFor($borrower);

        $stripeProvider = PaymentProvider::factory()->stripe()->create();
        $partnerProvider = PartnerPaymentProvider::factory()->create([
            'partner_id' => $borrower->partner_id,
            'payment_provider_id' => $stripeProvider->id,
        ]);

        $subscription = Subscription::factory()->forBorrower($borrower)->create();
        $invoice = Invoice::factory()->forBorrower($borrower)->unpaid()->create([
            'subscription_id' => $subscription->id,
        ]);
        $subscription->update(['current_cycle_invoice_id' => $invoice->id]);

        $driver = Mockery::mock(PaymentGatewayInterface::class);
        $driver->shouldReceive('charge')->once()->andReturn(['success' => false]);

        $gatewayManager = Mockery::mock(PaymentGatewayManager::class);
        $gatewayManager->shouldReceive('resolvePartnerProvider')->once()->andReturn($partnerProvider->fresh(['provider']));
        $gatewayManager->shouldReceive('driverFor')->once()->andReturn($driver);

        $service = $this->makeService($gatewayManager);

        try {
            $service->processPayment([
                'invoice_id' => $invoice->id,
                'payment_method_id' => $partnerProvider->payment_provider_id,
                'payment_token' => 'pm_test_declined',
            ]);
            $this->fail('Expected an exception to be thrown for a declined charge.');
        } catch (\Exception $e) {
            // expected
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => Invoice::STATUS_UNPAID]);
        $this->assertDatabaseCount('payments', 0);
    }

    // ─────────────────────────────────────────────────────────────
    // triggerPartnerAutoChargeForBorrowerInvoice — safe (no-Stripe-call) branch only
    // ─────────────────────────────────────────────────────────────

    public function test_trigger_partner_auto_charge_records_failed_payment_when_partner_has_no_saved_card(): void
    {
        $host = Partner::factory()->host()->create();
        $partner = Partner::factory()->create();
        $borrowerFeeInvoice = Invoice::factory()->forPartner($partner)->unpaid()->borrowerFee()->create([
            'payment_amount' => 40.00,
        ]);

        $service = $this->makeService();

        // No PaymentGatewayManager call is expected: the method returns before
        // reaching Stripe because there is no prior successful Stripe payment
        // (and thus no saved payment_method) for this partner.
        $service->triggerPartnerAutoChargeForBorrowerInvoice($borrowerFeeInvoice);

        $this->assertDatabaseHas('payments', [
            'partner_id' => $partner->id,
            'invoice_id' => $borrowerFeeInvoice->id,
            'status' => Payment::STATUS_FAILED,
        ]);

        // Invoice must remain untouched (still Unpaid) since the auto-charge did not run.
        $this->assertSame(Invoice::STATUS_UNPAID, $borrowerFeeInvoice->fresh()->status);
    }

    public function test_trigger_partner_auto_charge_is_a_no_op_for_null_invoice(): void
    {
        $service = $this->makeService();

        // Should not throw.
        $service->triggerPartnerAutoChargeForBorrowerInvoice(null);

        $this->assertDatabaseCount('payments', 0);
    }
}
