<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * BUG-035: a card declined at checkout ("Pay Now") left no trace in Payments,
 * while a declined auto-charge did get a FAILED row. Each declined checkout
 * attempt is now recorded as FAILED; the invoice stays Unpaid.
 */
#[CoversClass(UnifiedPaymentService::class)]
class CheckoutDeclineRecordedTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private PaymentProvider $stripe;
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
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_decline', 'secret_key' => 'sk_test_decline'],
                'production' => ['publishable_key' => 'pk_live_decline', 'secret_key' => 'sk_live_decline'],
            ],
        ]);

        $this->actingAs(User::create([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]));
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-DECLINE-1',
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'customer_name' => 'Host Owner',
            'email' => 'host@example.test',
            'payment_amount' => 800.00,
            'currency' => 'USD',
            'status' => $status,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function payAndExpectFailure(Invoice $invoice): Exception
    {
        try {
            app(UnifiedPaymentService::class)->processPayment([
                'invoice_id' => $invoice->id,
                'payment_method_id' => $this->stripe->id,
                'payment_token' => 'pm_card_chargeDeclined',
            ]);
        } catch (Exception $e) {
            return $e;
        }

        $this->fail('the payment was reported as a success');
    }

    /** Stripe's answer for test card 4000 0000 0000 0002. */
    private function stripeDeclinesTheCard(): void
    {
        $this->http->willFail('payment_intents', 402, [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'generic_decline',
            'message' => 'Your card was declined.',
        ]);
    }

    private function failedRows(Invoice $invoice)
    {
        return Payment::where('invoice_id', $invoice->id)->where('status', Payment::STATUS_FAILED)->get();
    }

    #[Test]
    public function a_declined_card_is_recorded_as_a_failed_payment(): void
    {
        $this->stripeDeclinesTheCard();
        $invoice = $this->invoice();

        $error = $this->payAndExpectFailure($invoice);

        $this->assertStringContainsString('declined', $error->getMessage(), 'the payer still sees why');
        $failed = $this->failedRows($invoice);
        $this->assertCount(1, $failed);
        $row = $failed->first();
        $this->assertEquals(800.00, $row->amount);
        $this->assertSame(PaymentProvider::STRIPE, $row->payment_from);
        $this->assertSame(Partner::class, $row->entity_type);
        $this->assertSame(Payment::FAILURE_SOURCE_CHECKOUT, $row->details[Payment::DETAIL_FAILURE_SOURCE]);
        $this->assertSame('generic_decline', $row->details['decline_code']);
        $this->assertSame('Your card was declined.', $row->details['error']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function every_declined_attempt_gets_its_own_row(): void
    {
        $this->stripeDeclinesTheCard();
        $invoice = $this->invoice();

        $this->payAndExpectFailure($invoice);
        $this->payAndExpectFailure($invoice);

        $this->assertCount(2, $this->failedRows($invoice));
    }

    #[Test]
    public function a_charge_stripe_leaves_unpaid_is_recorded_too(): void
    {
        $this->http->willReturn('payment_intents', [
            'id' => 'pi_declined',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
        ]);
        $invoice = $this->invoice();

        $this->payAndExpectFailure($invoice);

        $failed = $this->failedRows($invoice);
        $this->assertCount(1, $failed);
        $this->assertSame('pi_declined', $failed->first()->transaction_id);
    }

    #[Test]
    public function a_payment_refused_before_the_card_is_charged_records_nothing(): void
    {
        $invoice = $this->invoice(Invoice::STATUS_PAID);

        $this->payAndExpectFailure($invoice);

        $this->assertSame(0, Payment::count());
        $this->assertFalse($this->http->called('payment_intents'));
    }
}
