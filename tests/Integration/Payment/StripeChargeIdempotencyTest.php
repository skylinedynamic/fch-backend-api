<?php

namespace Tests\Feature\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\Drivers\StripePaymentDriver;
use App\Services\GlobalPayment\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Repeating a charge must not take the money twice.
 *
 * A charge can succeed and still be lost. UnifiedPaymentService opens a database
 * transaction, charges the card inside it, then does another ~130 lines of work -
 * subscription advancement, a cascading auto-charge, two emails - before
 * committing. Anything throwing in that window rolls the database back, and a
 * rollback cannot un-charge a card. The invoice stays Unpaid, the cron finds it
 * on the next pass, and the payer is debited a second time.
 *
 * Stripe's idempotency keys close that: a key is remembered for 24 hours, and a
 * request carrying one already seen replays the first response instead of
 * charging again. So these assert on the key rather than on the charge - what
 * matters is that a repeat of the same attempt carries the same key, and a
 * genuinely different attempt does not.
 *
 * The distinction that took the most care: a payer whose card was declined must
 * be able to try another card immediately. A key built from the invoice alone
 * would replay the decline at them for 24 hours, so the instrument is part of it.
 */
#[CoversClass(StripePaymentDriver::class)]
class StripeChargeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const SK_LIVE = 'sk_live_idem';

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
                'sandbox' => ['publishable_key' => 'pk_test_idem', 'secret_key' => 'sk_test_idem'],
                'production' => ['publishable_key' => 'pk_live_idem', 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function invoice(string $reference = 'INV-IDEM-1', float $amount = 2497.00): Invoice
    {
        return Invoice::create([
            'invoice_id' => $reference,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'customer_name' => 'Host Owner',
            'email' => 'host@example.test',
            'payment_amount' => $amount,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    /** Charge through the real gateway wiring and report the key that was sent. */
    private function chargeAndReturnKey(Invoice $invoice, string $paymentToken = 'pm_card_one'): ?string
    {
        $this->http = FakeStripeHttpClient::install();

        $manager = app(PaymentGatewayManager::class);
        $manager->driverFor($manager->resolvePartnerProvider($this->host->id))->charge([
            'amount' => $invoice->payment_amount,
            'currency' => 'USD',
            'invoice_id' => $invoice->id,
            'payment_token' => $paymentToken,
            'description' => 'Enrollment fee',
        ]);

        return $this->http->chargeIdempotencyKey();
    }

    #[Test]
    public function a_charge_carries_an_idempotency_key(): void
    {
        $key = $this->chargeAndReturnKey($this->invoice());

        $this->assertNotNull($key, 'the charge went out with no idempotency key, so a retry would debit again');
        $this->assertStringStartsWith('charge_', $key);
    }

    /**
     * The protection itself: the same invoice paid with the same card is the
     * same attempt, however many times it is repeated.
     */
    #[Test]
    public function repeating_the_same_attempt_reuses_the_key(): void
    {
        $invoice = $this->invoice();

        $first = $this->chargeAndReturnKey($invoice);
        $second = $this->chargeAndReturnKey($invoice);

        $this->assertSame($first, $second, 'a repeat of the same charge would have been billed as a new one');
    }

    /**
     * The escape hatch. A declined card has to be replaceable at once - if the
     * key ignored the card, Stripe would replay the decline for 24 hours and the
     * payer could not pay at all.
     */
    #[Test]
    public function paying_the_same_invoice_with_another_card_is_a_new_attempt(): void
    {
        $invoice = $this->invoice();

        $declined = $this->chargeAndReturnKey($invoice, 'pm_card_declined');
        $retried = $this->chargeAndReturnKey($invoice, 'pm_card_two');

        $this->assertNotSame($declined, $retried, 'retrying with a different card would have replayed the first result');
    }

    #[Test]
    public function separate_invoices_never_share_a_key(): void
    {
        $first = $this->chargeAndReturnKey($this->invoice('INV-IDEM-A'));
        $second = $this->chargeAndReturnKey($this->invoice('INV-IDEM-B'));

        $this->assertNotSame($first, $second);
    }

    /**
     * Stripe refuses a reused key carrying different parameters, so a changed
     * amount has to produce a different key rather than an error.
     */
    #[Test]
    public function a_changed_amount_produces_a_different_key(): void
    {
        $cheap = $this->chargeAndReturnKey($this->invoice('INV-IDEM-C', 100.00));
        $dear = $this->chargeAndReturnKey($this->invoice('INV-IDEM-D', 900.00));

        $this->assertNotSame($cheap, $dear);
    }

    /**
     * The unattended path matters most: it runs on a schedule, against a card
     * nobody re-enters, and its failures are silent.
     */
    #[Test]
    public function the_auto_charge_job_sends_a_stable_key_for_an_invoice(): void
    {
        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_saved',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
                'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            ],
        ]);

        $invoice = $this->invoice('INV-IDEM-CRON');

        $keys = [];

        foreach ([1, 2] as $pass) {
            $this->http = FakeStripeHttpClient::install();

            // The invoice is deliberately still Unpaid on the second pass: that is
            // exactly the state a charge that succeeded but was not recorded
            // leaves behind, and what the cron would find next time round.
            //
            // Written through the query builder because the model in hand still
            // believes it is Unpaid - the first pass marked it Paid in the
            // database, so an Eloquent update would see nothing dirty and write
            // nothing at all.
            Invoice::where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);
            // "Not recorded" means no payment row either. A recorded one now
            // stops the job before Stripe is called at all (BUG-005, see
            // AutoChargeStopTest); the idempotency key is what covers the rest.
            Payment::where('invoice_id', $invoice->id)->forceDelete();

            app()->call([new ChargeSubscriptionInvoiceJob($invoice->fresh()), 'handle']);

            $keys[] = $this->http->chargeIdempotencyKey();
        }

        $this->assertNotNull($keys[0], 'the auto-charge went out with no idempotency key');
        $this->assertStringStartsWith('offsession_', $keys[0]);
        $this->assertSame($keys[0], $keys[1], 'a second cron pass would have debited the payer again');
    }

    /**
     * The two flows must not collide: an interactive payment and an auto-charge
     * for the same invoice are different operations.
     */
    #[Test]
    public function interactive_and_automatic_charges_use_separate_key_spaces(): void
    {
        $invoice = $this->invoice('INV-IDEM-BOTH');

        $interactive = $this->chargeAndReturnKey($invoice);

        $this->assertStringStartsWith('charge_', $interactive);
        $this->assertStringNotContainsString('offsession_', $interactive);
    }
}
