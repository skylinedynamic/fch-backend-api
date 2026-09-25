<?php

namespace Tests\Feature\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
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
 * A card whose bank wants the holder to authenticate.
 *
 * Stripe answers `requires_action` when the issuer demands 3-D Secure - usually
 * an OTP. The card is fine; the charge is simply waiting on a step this
 * application does not implement.
 *
 * That full flow is not built, deliberately: it needs the browser to confirm with
 * `client_secret` and a webhook to catch a payer who closes the tab midway, and
 * US-issued cards rarely trigger it. What is built is the ability to TELL. The
 * code used to fold this into a generic "Payment charge failed via Stripe",
 * leaving the payer with nothing to act on and no trace of the cause - the same
 * dead end as an error naming a token while the real fault lay in a setting.
 *
 * So these pin the naming, not the handling. If the warning they describe starts
 * appearing in production logs, that is the evidence for building the real flow.
 */
#[CoversClass(UnifiedPaymentService::class)]
class CardAuthenticationRequiredTest extends TestCase
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
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_3ds', 'secret_key' => 'sk_test_3ds'],
                'production' => ['publishable_key' => 'pk_live_3ds', 'secret_key' => 'sk_live_3ds'],
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

    /** Stripe's answer for a card the issuer wants authenticated. */
    private function stripeAsksForAuthentication(): void
    {
        $this->http->willReturn('payment_intents', [
            'id' => 'pi_needs_3ds',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'client_secret' => 'pi_needs_3ds_secret',
            'customer' => 'cus_fake',
            'payment_method' => 'pm_fake',
        ]);
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-3DS-1',
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
            'payment_token' => 'pm_needs_authentication',
        ]);
    }

    /**
     * The point of the whole change: the payer is told what to do about it.
     */
    #[Test]
    public function the_payer_is_told_the_card_needs_bank_verification(): void
    {
        $this->stripeAsksForAuthentication();

        try {
            $this->pay($this->invoice());
            $this->fail('an unauthenticated charge was reported as a success');
        } catch (Exception $e) {
            $this->assertStringContainsString('verification from your bank', $e->getMessage());
            $this->assertStringNotContainsString('Payment charge failed', $e->getMessage());
        }
    }

    /**
     * A genuine decline must keep reading as a decline - this only carves out the
     * one case it can actually identify.
     */
    #[Test]
    public function an_ordinary_decline_still_reads_as_a_decline(): void
    {
        $this->http->willReturn('payment_intents', [
            'id' => 'pi_declined',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
        ]);

        try {
            $this->pay($this->invoice());
            $this->fail('a declined charge was reported as a success');
        } catch (Exception $e) {
            $this->assertStringContainsString('Payment charge failed', $e->getMessage());
        }
    }

    /**
     * Nothing may be recorded: the money has not moved, whatever the reason.
     */
    #[Test]
    public function an_unauthenticated_charge_records_nothing(): void
    {
        $this->stripeAsksForAuthentication();

        $invoice = $this->invoice();

        try {
            $this->pay($invoice);
        } catch (Exception) {
            // expected
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    /**
     * The unattended path says it too. An off-session charge can never complete
     * an authentication challenge, so this card will fail on every pass until the
     * payer replaces it - a "declined" line would have it retried forever as if
     * the next attempt might land.
     */
    #[Test]
    public function the_auto_charge_job_names_the_authentication_failure(): void
    {
        $this->stripeAsksForAuthentication();

        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_saved',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 3155',
                'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            ],
        ]);

        $invoice = $this->invoice();

        app()->call([new ChargeSubscriptionInvoiceJob($invoice), 'handle']);

        $failure = Payment::where('status', Payment::STATUS_FAILED)->latest('id')->first();

        $this->assertNotNull($failure, 'the failed auto-charge was not recorded');
        $this->assertStringContainsString('3-D Secure', (string) json_encode($failure->toArray()));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }
}
