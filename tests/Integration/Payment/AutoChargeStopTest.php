<?php

namespace Tests\Integration\Payment;

use App\Console\Commands\ProcessAllSubscriptions;
use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Mail\CronJobFailureMail;
use App\Mail\Payment\AutoChargeStoppedMail;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * BUG-005: an invoice paid by Stripe on 19 Jul stayed Unpaid (older code), and
 * the scheduler tried to charge it again twice a day - 29 attempts by 25 Oct.
 * Stripe forgets an idempotency key after 24 hours, so a working card would
 * have been charged a second time.
 *
 * Auto-charge now stops for good on an invoice:
 *  - whose succeeded payments already cover it - the admins are told once, to
 *    reconcile it (php artisan invoices:reconcile-paid);
 *  - after 6 failed attempts - the payer, and a client's partner, are told once.
 * The invoice stays payable by hand either way.
 */
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
#[CoversClass(ProcessAllSubscriptions::class)]
class AutoChargeStopTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.cron_notify_emails' => 'ops@example.test']);
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
                'sandbox' => ['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x'],
                'production' => ['publishable_key' => 'pk_live_x', 'secret_key' => 'sk_live_x'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function partnerPayer(bool $withCard): Partner
    {
        return Partner::create([
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Payer Co',
            'full_name' => 'Pat Payer',
            'email' => 'payer@example.test',
            'default_payment_method' => $withCard ? PaymentProvider::STRIPE : null,
            'stripe_customer_id' => $withCard
                ? ['customer_id' => 'cus_saved', 'name' => 'Pat Payer', 'card_no' => '**** 4242', 'payment_mode' => PaymentProvider::MODE_PRODUCTION]
                : null,
        ]);
    }

    private function invoiceFor(Partner|Borrower $payer, float $amount = 2497.00): Invoice
    {
        $isBorrower = $payer instanceof Borrower;

        return Invoice::create([
            'invoice_id' => 'INV-20260719001',
            'partner_id' => $isBorrower ? $payer->partner_id : $payer->id,
            'borrower_id' => $isBorrower ? $payer->id : null,
            'entity_type' => get_class($payer),
            'entity_id' => $payer->id,
            'customer_name' => 'Kirsten Carter',
            'email' => $payer->email,
            'payment_amount' => $amount,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function succeededPayment(Invoice $invoice, float $amount): void
    {
        (new Payment())->createRecord([
            'partner_id' => $invoice->partner_id,
            'borrower_id' => $invoice->borrower_id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'pi_19jul',
            'amount' => $amount,
            'currency' => 'USD',
            'payment_from' => PaymentProvider::STRIPE,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    private function runCron(Invoice $invoice): void
    {
        app()->call([new ChargeSubscriptionInvoiceJob($invoice->fresh()), 'handle']);
    }

    private function failures(Invoice $invoice): int
    {
        return Payment::where('invoice_id', $invoice->id)->where('status', Payment::STATUS_FAILED)->count();
    }

    // ── Already paid ──────────────────────────────────────────────────

    #[Test]
    public function an_invoice_its_succeeded_payments_already_cover_is_never_charged_again(): void
    {
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: true));
        $this->succeededPayment($invoice, 2497.00);

        $this->runCron($invoice);
        $this->runCron($invoice);

        $this->assertFalse($this->http->called('payment_intents'), 'Stripe must not be asked for the money again');
        $this->assertSame(0, $this->failures($invoice), 'no failed attempt is recorded either');
        $invoice->refresh();
        $this->assertNotNull($invoice->auto_charge_stopped_at);
        $this->assertSame(Invoice::AUTO_CHARGE_STOP_ALREADY_PAID, $invoice->auto_charge_stop_reason);
    }

    #[Test]
    public function the_admins_are_told_once_to_reconcile_it_and_the_payer_is_not_told(): void
    {
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: true));
        $this->succeededPayment($invoice, 2497.00);

        $this->runCron($invoice);
        $this->runCron($invoice);

        Mail::assertQueued(CronJobFailureMail::class, 1);
        Mail::assertQueued(CronJobFailureMail::class, fn ($mail) => $mail->hasTo('ops@example.test'));
        Mail::assertNotQueued(AutoChargeStoppedMail::class);
    }

    #[Test]
    public function a_payment_that_covers_only_part_of_the_invoice_does_not_stop_it(): void
    {
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: false));
        $this->succeededPayment($invoice, 1000.00);

        $this->runCron($invoice);

        $this->assertNull($invoice->fresh()->auto_charge_stopped_at);
        $this->assertSame(1, $this->failures($invoice), 'it is still attempted');
    }

    // ── Six failed attempts ───────────────────────────────────────────

    #[Test]
    public function auto_charge_stops_after_six_failed_attempts(): void
    {
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: false));

        foreach (range(1, 5) as $_) {
            $this->runCron($invoice);
        }
        $this->assertNull($invoice->fresh()->auto_charge_stopped_at, 'five failures do not stop it');

        $this->runCron($invoice);
        $invoice->refresh();
        $this->assertNotNull($invoice->auto_charge_stopped_at);
        $this->assertSame(Invoice::AUTO_CHARGE_STOP_TOO_MANY_FAILURES, $invoice->auto_charge_stop_reason);

        $this->runCron($invoice);
        $this->assertSame(6, $this->failures($invoice), 'nothing is attempted after the sixth');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'the debt stands');
    }

    #[Test]
    public function failures_from_before_count_towards_the_six(): void
    {
        // Kirsten's invoice already had 29 failures when this shipped.
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: false));
        foreach (range(1, 29) as $_) {
            (new Payment())->createRecord([
                'invoice_id' => $invoice->id, 'partner_id' => $invoice->partner_id, 'entity_type' => $invoice->entity_type,
                'entity_id' => $invoice->entity_id, 'transaction_id' => 'FAILED_' . uniqid(), 'amount' => 2497.00,
                'currency' => 'USD', 'payment_from' => PaymentProvider::STRIPE, 'status' => Payment::STATUS_FAILED,
            ]);
        }

        $this->runCron($invoice);

        $this->assertNotNull($invoice->fresh()->auto_charge_stopped_at);
    }

    /**
     * BUG-035: declined "Pay Now" attempts are recorded as FAILED too, but they
     * are the payer trying by hand - they must not use up the cron's six.
     */
    #[Test]
    public function declined_checkout_attempts_do_not_count_towards_the_six(): void
    {
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: false));
        foreach (range(1, 6) as $_) {
            (new Payment())->createRecord([
                'invoice_id' => $invoice->id, 'partner_id' => $invoice->partner_id, 'entity_type' => $invoice->entity_type,
                'entity_id' => $invoice->entity_id, 'transaction_id' => 'FAILED_' . uniqid(), 'amount' => 2497.00,
                'currency' => 'USD', 'payment_from' => PaymentProvider::STRIPE, 'status' => Payment::STATUS_FAILED,
                'details' => [Payment::DETAIL_FAILURE_SOURCE => Payment::FAILURE_SOURCE_CHECKOUT],
            ]);
        }

        $this->runCron($invoice);

        $this->assertNull($invoice->fresh()->auto_charge_stopped_at);
    }

    #[Test]
    public function a_partner_paying_its_own_invoice_is_told_once_that_auto_charge_stopped(): void
    {
        $payer = $this->partnerPayer(withCard: false);
        $invoice = $this->invoiceFor($payer);

        foreach (range(1, 8) as $_) {
            $this->runCron($invoice);
        }

        Mail::assertQueued(AutoChargeStoppedMail::class, 1);
        Mail::assertQueued(AutoChargeStoppedMail::class, fn ($mail) => $mail->hasTo('payer@example.test'));
    }

    #[Test]
    public function a_client_and_the_clients_partner_are_both_told_once(): void
    {
        $partner = $this->partnerPayer(withCard: false);
        $partner->update(['email' => 'partner-owner@example.test']);
        $client = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Kirsten',
            'last_name' => 'Carter',
            'email' => 'kirsten@example.test',
        ]);
        $invoice = $this->invoiceFor($client);

        foreach (range(1, 8) as $_) {
            $this->runCron($invoice);
        }

        Mail::assertQueued(AutoChargeStoppedMail::class, 2);
        Mail::assertQueued(AutoChargeStoppedMail::class, fn ($mail) => $mail->hasTo('kirsten@example.test'));
        Mail::assertQueued(AutoChargeStoppedMail::class, fn ($mail) => $mail->hasTo('partner-owner@example.test'));
    }

    #[Test]
    public function the_email_names_the_invoice_and_tells_the_payer_to_pay_it_themselves(): void
    {
        $invoice = $this->invoiceFor($this->partnerPayer(withCard: false));

        foreach (range(1, 6) as $_) {
            $this->runCron($invoice);
        }

        Mail::assertQueued(AutoChargeStoppedMail::class, function (AutoChargeStoppedMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'INV-20260719001') && str_contains($html, 'Pay Now');
        });
    }

    // ── The scheduler ─────────────────────────────────────────────────

    #[Test]
    public function the_scheduler_does_not_queue_an_invoice_whose_auto_charge_has_stopped(): void
    {
        Queue::fake();
        $payer = $this->partnerPayer(withCard: false);
        $subscription = Subscription::create([
            'entity_type' => Partner::class,
            'entity_id' => $payer->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_phase' => Subscription::PHASE_PLATFORM_FEE,
            'subscription_end_date' => now()->addMonth(),
        ]);
        $stopped = $this->invoiceFor($payer);
        $stopped->update(['subscription_id' => $subscription->id, 'auto_charge_stopped_at' => now(), 'auto_charge_stop_reason' => Invoice::AUTO_CHARGE_STOP_TOO_MANY_FAILURES]);

        $this->artisan('subscriptions:process-all')->assertSuccessful();

        Queue::assertNotPushed(ChargeSubscriptionInvoiceJob::class, fn ($job) => $job->invoice->is($stopped));
    }
}
