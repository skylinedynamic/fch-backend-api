<?php

namespace Tests\Integration\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Mail\Payment\CardReauthorizationRequiredMail;
use App\Mail\Payment\PaymentFailedMail;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Stripe\Exception\CardException;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Telling a payer their automatic payment did not go through (findings O46).
 *
 * Defect: when the auto-charge cron could not take a payment - the card was
 * declined, or there was no saved card to charge - only the admins' cron alert
 * went out. The payer was never told, so unpaid invoices piled up until their
 * access was limited. PaymentFailedMail existed for exactly this and was never
 * sent from anywhere.
 *
 * Like the card re-authorization request it is sent once per invoice - the cron
 * runs twice a day - and only for failures the payer can act on: a gateway or
 * system error is the admins' to fix and still goes to them alone.
 */
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
class PaymentFailedMailTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
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

        $stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_fail', 'secret_key' => 'sk_test_fail'],
                'production' => ['publishable_key' => 'pk_live_fail', 'secret_key' => 'sk_live_fail'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function payerWithCard(string $mode = PaymentProvider::MODE_PRODUCTION): Partner
    {
        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => ['customer_id' => 'cus_saved', 'name' => 'A Cardholder', 'card_no' => '**** 4242', 'payment_mode' => $mode],
        ]);

        return $this->host->fresh();
    }

    private function payerWithoutCard(): Partner
    {
        $this->host->update(['default_payment_method' => null, 'stripe_customer_id' => null]);

        return $this->host->fresh();
    }

    private function invoiceFor(Partner $payer, string $reference = 'INV-FAIL-1'): Invoice
    {
        return Invoice::create([
            'invoice_id' => $reference,
            'partner_id' => $payer->id,
            'entity_type' => Partner::class,
            'entity_id' => $payer->id,
            'customer_name' => $payer->full_name,
            'email' => $payer->email,
            'payment_amount' => 199.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_PLATFORM_FEE,
        ]);
    }

    private function runCron(Invoice $invoice): void
    {
        app()->call([new ChargeSubscriptionInvoiceJob($invoice->fresh()), 'handle']);
    }

    /** Stripe declines the card, as it does: a 402 CardException on the PaymentIntent. */
    private function cardIsDeclined(): void
    {
        $this->http->onRequest = function (string $url) {
            if (str_contains($url, '/v1/payment_intents')) {
                throw CardException::factory('Your card was declined.', 402, null, null, null, 'card_declined', 'generic_decline');
            }
        };
    }

    #[Test]
    public function a_declined_card_tells_the_payer_once(): void
    {
        $payer = $this->payerWithCard();
        $this->cardIsDeclined();
        $invoice = $this->invoiceFor($payer);

        $this->runCron($invoice);
        $this->runCron($invoice);
        $this->runCron($invoice);

        Mail::assertQueued(PaymentFailedMail::class, 1);
        Mail::assertQueued(PaymentFailedMail::class, fn ($mail) => $mail->hasTo($payer->email));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function a_charge_stripe_does_not_complete_tells_the_payer(): void
    {
        $payer = $this->payerWithCard();
        $this->http->willReturn('payment_intents', ['id' => 'pi_x', 'object' => 'payment_intent', 'status' => 'requires_payment_method']);

        $this->runCron($this->invoiceFor($payer));

        Mail::assertQueued(PaymentFailedMail::class, 1);
    }

    /** No card to charge - never saved, or removed (F110): the payer is told to pay it themselves. */
    #[Test]
    public function a_payer_with_no_saved_card_is_told_once(): void
    {
        $payer = $this->payerWithoutCard();
        $invoice = $this->invoiceFor($payer);

        $this->runCron($invoice);
        $this->runCron($invoice);

        Mail::assertQueued(PaymentFailedMail::class, 1);
        Mail::assertQueued(PaymentFailedMail::class, fn ($mail) => $mail->hasTo($payer->email));
    }

    /** The email says which invoice, why, and where to go - not a bare "payment failed". */
    #[Test]
    public function the_email_names_the_invoice_and_links_to_the_billing_page(): void
    {
        $this->runCron($this->invoiceFor($this->payerWithoutCard(), 'INV-FAIL-77'));

        Mail::assertQueued(PaymentFailedMail::class, function (PaymentFailedMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'INV-FAIL-77')
                && str_contains($html, 'no default payment method')
                && str_contains($html, 'Pay Now');
        });
    }

    #[Test]
    public function each_unpaid_invoice_is_told_on_its_own(): void
    {
        $payer = $this->payerWithoutCard();

        $this->runCron($this->invoiceFor($payer, 'INV-FAIL-A'));
        $this->runCron($this->invoiceFor($payer, 'INV-FAIL-B'));

        Mail::assertQueued(PaymentFailedMail::class, 2);
    }

    #[Test]
    public function the_payer_is_also_told_in_their_bell_with_a_link_to_their_billing_page(): void
    {
        Notification::fake();
        $payer = $this->payerWithoutCard();
        $login = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Host Owner', 'email' => 'owner@example.test', 'password' => bcrypt('secret'),
            'user_type' => User::PARTNER, 'entity_type' => Partner::class, 'entity_id' => $payer->id,
            'partner_id' => $payer->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->runCron($this->invoiceFor($payer));

        Notification::assertSentTo($login, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'partner.payment.failed'
            && $n->toArray($login)['url'] === '/my-subscription');
    }

    /** A gateway or system error is not the payer's to fix: admins only, as before. */
    #[Test]
    public function a_system_error_does_not_email_the_payer(): void
    {
        $payer = $this->payerWithCard();
        $this->http->onRequest = function (string $url) {
            if (str_contains($url, '/v1/payment_intents')) {
                throw new \RuntimeException('Stripe could not be reached');
            }
        };

        $this->runCron($this->invoiceFor($payer));

        Mail::assertNotQueued(PaymentFailedMail::class);
    }

    /** A card saved in the other Stripe account keeps its own, more specific request. */
    #[Test]
    public function a_card_that_must_be_re_entered_gets_only_the_re_entry_request(): void
    {
        $payer = $this->payerWithCard(PaymentProvider::MODE_SANDBOX);

        $this->runCron($this->invoiceFor($payer));

        Mail::assertQueued(CardReauthorizationRequiredMail::class, 1);
        Mail::assertNotQueued(PaymentFailedMail::class);
    }
}
