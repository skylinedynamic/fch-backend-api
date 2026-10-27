<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\PaymentMailService;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Where the database transaction begins and ends around a payment.
 *
 * A charge is not undone by a rollback. The gateway call used to sit inside a
 * transaction that stayed open across another ~130 lines - subscription
 * advancement, a second cascading charge, two emails - so a failure in any of
 * them erased the record of money that had already left the payer's account. The
 * invoice went back to Unpaid, and the cron charged them again on the next pass.
 *
 * The ordering that fixes it is the one the auto-charge job already used: take
 * the money, record it in the shortest transaction that will hold the records,
 * then do everything that can fail on its own.
 *
 * These assert the ordering directly rather than its symptoms. The stubbed Stripe
 * client reports the open transaction depth at the instant it is called, which is
 * the one moment that matters.
 */
#[CoversClass(UnifiedPaymentService::class)]
class PaymentTransactionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private PaymentProvider $stripe;
    private User $payerUser;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->http = FakeStripeHttpClient::install();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->stripe = PaymentProvider::create([
            'name' => PaymentProvider::STRIPE,
            'display_name' => 'Stripe',
            'status' => true,
        ]);

        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_tx', 'secret_key' => 'sk_test_tx'],
                'production' => ['publishable_key' => 'pk_live_tx', 'secret_key' => 'sk_live_tx'],
            ],
        ]);

        $this->payerUser = User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);

        $this->actingAs($this->payerUser);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-TX-1',
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'customer_name' => 'Host Owner',
            'email' => 'host@example.test',
            'payment_amount' => 2497.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function pay(Invoice $invoice): array
    {
        return app(UnifiedPaymentService::class)->processPayment([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->stripe->id,
            'payment_token' => 'pm_tokenized_in_the_browser',
        ]);
    }

    /**
     * The structural assertion: no transaction may be open while money moves.
     *
     * Anything held open across the gateway call can be rolled back after the
     * charge has already gone through, which is precisely what cannot be undone.
     */
    #[Test]
    public function no_transaction_is_open_while_the_gateway_is_charged(): void
    {
        // RefreshDatabase wraps each test in a transaction of its own, so depth is
        // measured against that baseline rather than against zero.
        $baseline = DB::transactionLevel();

        $levels = [];
        $this->http->onRequest = function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        };

        $this->pay($this->invoice());

        $this->assertNotEmpty($levels, 'the gateway was never called');
        $this->assertSame(
            [$baseline],
            array_values(array_unique($levels)),
            'a database transaction was open while the card was charged, so a later failure could erase the record of it',
        );
    }

    /**
     * The payment is still recorded normally - the reordering must not have cost
     * anything.
     */
    #[Test]
    public function a_successful_payment_is_recorded_and_the_invoice_is_paid(): void
    {
        $invoice = $this->invoice();

        $result = $this->pay($invoice);

        $this->assertTrue($result['success']);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    /**
     * The failure that used to cost real money.
     *
     * An SMTP server having a bad minute is an ordinary event. It used to throw
     * inside the transaction, rolling back a payment that Stripe had already
     * taken - and the payer was charged again on the next cron pass. Now the
     * records are committed first, and a mail failure is logged rather than
     * raised.
     */
    #[Test]
    public function a_failing_email_cannot_undo_a_payment_that_went_through(): void
    {
        $this->mockFailingMailService();

        $invoice = $this->invoice();

        $result = $this->pay($invoice);

        $this->assertTrue($result['success'], 'a mail failure was reported back as a failed payment');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    /**
     * And the ledger survives it - the point of committing first.
     */
    #[Test]
    public function the_ledger_entry_survives_a_failing_email(): void
    {
        $this->mockFailingMailService();

        $this->pay($this->invoice());

        $this->assertSame(1, Payment::count(), 'the payment record was rolled back by a mail failure');
    }

    /**
     * The ordering, asserted directly rather than through its symptoms.
     *
     * The records must already be committed by the time any side effect runs. It
     * is not enough that mail failures are caught: holding the transaction open
     * across them also spans a second gateway call in step 6 and an SMTP
     * round-trip in step 7, keeping row locks for the length of two network
     * calls. Committing first is what makes the payment durable independently of
     * anything that follows.
     */
    #[Test]
    public function the_payment_is_committed_before_any_side_effect_runs(): void
    {
        $baseline = DB::transactionLevel();

        $levelWhenMailed = null;
        $this->app->instance(PaymentMailService::class, new class($levelWhenMailed) extends PaymentMailService {
            public function __construct(private &$captured)
            {
            }

            public function sendSuccess($payment, $invoice, $providerName = null, $partnerId = null): void
            {
                $this->captured = DB::transactionLevel();
            }

            public function sendSubmitted($payment, $invoice, $providerName = null, $bankDetail = null, $partnerId = null): void
            {
                $this->captured = DB::transactionLevel();
            }
        });

        $this->pay($this->invoice());

        $this->assertNotNull($levelWhenMailed, 'no email was sent, so the ordering was never exercised');
        $this->assertSame(
            $baseline,
            $levelWhenMailed,
            'the payment transaction was still open while mail was being sent, so an SMTP round-trip was holding row locks',
        );
    }

    /**
     * A charge that never happened must not be recorded as money taken. Since
     * BUG-035 the declined attempt itself is kept, as one FAILED row.
     */
    #[Test]
    public function a_declined_charge_records_only_the_failed_attempt(): void
    {
        $this->http->willReturn('payment_intents', [
            'id' => 'pi_declined',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
        ]);

        $baseline = DB::transactionLevel();
        $invoice = $this->invoice();

        try {
            $this->pay($invoice);
            $this->fail('a declined charge was reported as a success');
        } catch (Exception $e) {
            // expected
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([Payment::STATUS_FAILED], Payment::pluck('status')->all(), 'only the failed attempt is recorded');
        $this->assertSame($baseline, DB::transactionLevel(), 'a transaction was left open after a failed charge');
    }

    /**
     * Nothing may be left half-open for the next query to inherit, whichever way
     * the payment went.
     */
    #[Test]
    public function no_transaction_is_left_open_afterwards(): void
    {
        $baseline = DB::transactionLevel();

        $this->pay($this->invoice());

        $this->assertSame($baseline, DB::transactionLevel());
    }

    /** Replace the mail service with one that always fails, as a real SMTP outage would. */
    private function mockFailingMailService(): void
    {
        $failing = new class extends PaymentMailService {
            public function __construct()
            {
            }

            public function sendSuccess($payment, $invoice, $providerName = null, $partnerId = null): void
            {
                throw new Exception('SMTP connection timed out');
            }

            public function sendSubmitted($payment, $invoice, $providerName = null, $bankDetail = null, $partnerId = null): void
            {
                throw new Exception('SMTP connection timed out');
            }
        };

        $this->app->instance(PaymentMailService::class, $failing);
    }
}
