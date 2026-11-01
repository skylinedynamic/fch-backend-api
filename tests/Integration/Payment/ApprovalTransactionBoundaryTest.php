<?php

namespace Tests\Integration\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\Payment\PaymentService;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\PaymentMailService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Where the database transaction ends when a manual payment is approved.
 *
 * Approving a white-label partner's borrower's first payment also charges the
 * partner's card for the borrower fee, on the host's Stripe. That charge ran
 * inside approvePayment()'s transaction, before the subscription was advanced
 * and the approval email sent. A failure in either rolled the approval back -
 * and the fee's payment record with it - while the money stayed taken; the next
 * approval or cron pass charged the partner again. The ordering that fixes it is
 * the one Pay Now already uses (PaymentTransactionBoundaryTest): record in the
 * transaction, then charge and mail after it commits.
 */
#[CoversClass(PaymentService::class)]
class ApprovalTransactionBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->http = FakeStripeHttpClient::install();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => ['customer_id' => 'cus_partner', 'name' => 'A Cardholder', 'card_no' => '4242', 'payment_mode' => PaymentProvider::MODE_PRODUCTION],
        ]);

        $stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_ap', 'secret_key' => 'sk_test_ap'],
                'production' => ['publishable_key' => 'pk_live_ap', 'secret_key' => 'sk_live_ap'],
            ],
        ]);

        // The card the partner paid the host with before.
        (new Payment())->createRecord([
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'amount' => 10,
            'payment_from' => PaymentProvider::STRIPE,
            'status' => Payment::STATUS_SUCCEEDED,
            'is_environment' => PaymentProvider::ENVIRONMENT_PRODUCTION,
            'details' => ['payment_method' => 'pm_partner', 'customer' => 'cus_partner'],
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    /**
     * The partner's borrower paid its enrollment in cash (Processing); the fee
     * the partner owes the host is still Unpaid. Returns [payment, fee].
     */
    private function cashPaymentAwaitingApproval(): array
    {
        $borrower = $this->makeBorrower($this->partner, ['status' => Borrower::STATUS_ONBOARDING]);

        $subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 2497.00,
            'monthly_platform_fee' => 0,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'status' => Subscription::STATUS_PENDING,
        ]);

        $enrollment = Invoice::create([
            'invoice_id' => 'INV-ENR-' . uniqid(),
            'subscription_id' => $subscription->id,
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'payment_amount' => 2497.00,
            'status' => Invoice::STATUS_PROCESSING,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'currency' => 'USD',
        ]);
        $subscription->update(['current_cycle_invoice_id' => $enrollment->id]);

        $fee = Invoice::create([
            'invoice_id' => 'INV-FEE-' . uniqid(),
            'subscription_id' => $subscription->id,
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'payment_amount' => 800.00,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
            'currency' => 'USD',
        ]);

        $payment = (new Payment())->createRecord([
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrower->id,
            'invoice_id' => $enrollment->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'amount' => 2497.00,
            'payment_from' => PaymentProvider::CASH,
            'is_manual' => true,
            'status' => Payment::STATUS_PROCESSING,
            'is_environment' => PaymentProvider::ENVIRONMENT_PRODUCTION,
        ]);

        return [$payment, $fee];
    }

    private function approveAsThePartner(Payment $payment): array
    {
        $this->actingAs($this->makePartnerAdmin($this->partner));

        return app(PaymentService::class)->approvePayment($payment->id);
    }

    private function approvalMailFails(): void
    {
        $this->app->instance(PaymentMailService::class, new class extends PaymentMailService {
            public function __construct()
            {
            }

            public function sendApproved(Payment $payment, ?Invoice $invoice = null, ?string $note = null): void
            {
                throw new Exception('SMTP connection timed out');
            }
        });
    }

    #[Test]
    public function the_borrower_fee_is_charged_after_the_approval_is_committed(): void
    {
        [$payment] = $this->cashPaymentAwaitingApproval();
        $baseline = DB::transactionLevel();

        $levels = [];
        $this->http->onRequest = function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        };

        $this->approveAsThePartner($payment);

        $this->assertNotEmpty($levels, 'the borrower fee was never charged');
        $this->assertSame([$baseline], array_values(array_unique($levels)),
            'the approval transaction was open while the partner\'s card was charged, so a later failure could erase the record of it');
    }

    #[Test]
    public function a_failing_approval_email_cannot_undo_the_approval_or_the_fee_taken(): void
    {
        [$payment, $fee] = $this->cashPaymentAwaitingApproval();
        $this->approvalMailFails();

        $result = $this->approveAsThePartner($payment);

        $this->assertTrue($result['status'], 'a mail failure was reported as a failed approval');
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $payment->invoice->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $fee->fresh()->status, 'the fee was taken but its record was rolled back');
        $this->assertSame(1, Payment::where('invoice_id', $fee->id)->where('status', Payment::STATUS_SUCCEEDED)->count());
    }

    #[Test]
    public function the_borrower_fee_charge_cannot_be_taken_twice(): void
    {
        [$payment] = $this->cashPaymentAwaitingApproval();

        $this->approveAsThePartner($payment);

        // The same key the cron uses for this invoice (AutoChargeDefaultMethodTest
        // pins that the two match), so neither path can take the fee twice.
        $this->assertNotNull($this->http->chargeIdempotencyKey(), 'the borrower fee charge carried no idempotency key, so a retry could debit the partner again');
    }

    #[Test]
    public function an_ordinary_approval_still_approves_and_charges_the_fee(): void
    {
        [$payment, $fee] = $this->cashPaymentAwaitingApproval();

        $result = $this->approveAsThePartner($payment);

        $this->assertTrue($result['status'], $result['message'] ?? '');
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $fee->fresh()->status);
        $this->assertSame(['sk_live_ap'], $this->http->apiKeysUsed());
    }
}
