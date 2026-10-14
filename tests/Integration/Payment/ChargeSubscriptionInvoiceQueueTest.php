<?php

namespace Tests\Feature\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Where the auto-charge job is queued, and that a duplicate cannot record twice.
 *
 * The job used to pin itself to the `database` connection. Production runs its
 * workers on Redis, so nothing ever read that connection: every charge sat in
 * the jobs table and invoices were never auto-charged. And because the cron
 * dispatches every Unpaid invoice twice a day, each invoice piled up a fresh
 * copy per pass.
 *
 * Stripe's idempotency key already stops a duplicate from taking the money
 * twice. What it cannot stop is two copies both RECORDING that one charge - two
 * succeeded Payment rows, and the subscription advanced twice - which is what
 * the uniqueness and the locked re-check guard against.
 */
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
class ChargeSubscriptionInvoiceQueueTest extends TestCase
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
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_saved',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
                'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            ],
        ]);

        $stripe = PaymentProvider::create([
            'name' => PaymentProvider::STRIPE,
            'display_name' => 'Stripe',
            'status' => true,
        ]);

        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_q', 'secret_key' => 'sk_test_q'],
                'production' => ['publishable_key' => 'pk_live_q', 'secret_key' => 'sk_live_q'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function invoice(string $reference = 'INV-QUEUE-1'): Invoice
    {
        return Invoice::create([
            'invoice_id' => $reference,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'customer_name' => 'Host Owner',
            'email' => 'host@example.test',
            'payment_amount' => 100.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]);
    }

    private function succeededPayments(Invoice $invoice): int
    {
        return Payment::where('invoice_id', $invoice->id)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->count();
    }

    /**
     * The production bug: a job pinned to `database` is never seen by workers
     * running on the app's default connection.
     */
    #[Test]
    public function it_is_queued_on_the_default_connection(): void
    {
        Queue::fake();

        ChargeSubscriptionInvoiceJob::dispatch($this->invoice());

        Queue::assertPushed(
            ChargeSubscriptionInvoiceJob::class,
            fn (ChargeSubscriptionInvoiceJob $job) => $job->connection === null
        );
    }

    /**
     * The cron dispatches every Unpaid invoice twice a day. While one copy is
     * still waiting, the next pass must not add another.
     */
    #[Test]
    public function an_invoice_is_queued_only_once_while_a_charge_is_pending(): void
    {
        Queue::fake();

        $invoice = $this->invoice();

        ChargeSubscriptionInvoiceJob::dispatch($invoice);
        ChargeSubscriptionInvoiceJob::dispatch($invoice->fresh());

        $this->assertInstanceOf(ShouldBeUnique::class, new ChargeSubscriptionInvoiceJob($invoice));
        Queue::assertPushedTimes(ChargeSubscriptionInvoiceJob::class, 1);
    }

    #[Test]
    public function different_invoices_are_queued_independently(): void
    {
        Queue::fake();

        ChargeSubscriptionInvoiceJob::dispatch($this->invoice('INV-QUEUE-A'));
        ChargeSubscriptionInvoiceJob::dispatch($this->invoice('INV-QUEUE-B'));

        Queue::assertPushedTimes(ChargeSubscriptionInvoiceJob::class, 2);
    }

    /**
     * Two copies racing: another worker finishes the invoice while this one's
     * charge is in flight. Stripe replays the same charge to both, so this copy
     * must notice the invoice is already Paid and record nothing.
     */
    #[Test]
    public function a_racing_duplicate_does_not_record_the_charge_a_second_time(): void
    {
        $invoice = $this->invoice();

        $this->http->onRequest = function (string $url) use ($invoice) {
            if (!str_contains($url, '/v1/payment_intents')) {
                return;
            }

            // What the other worker leaves behind once it has finished.
            (new Payment())->createRecord([
                'partner_id' => $invoice->partner_id,
                'invoice_id' => $invoice->id,
                'entity_type' => $invoice->entity_type,
                'entity_id' => $invoice->entity_id,
                'transaction_id' => 'pi_fake',
                'amount' => $invoice->payment_amount,
                'currency' => 'USD',
                'payment_from' => PaymentProvider::STRIPE,
                'status' => Payment::STATUS_SUCCEEDED,
                'details' => [],
            ]);
            Invoice::where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        };

        app()->call([new ChargeSubscriptionInvoiceJob($invoice->fresh()), 'handle']);

        $this->assertSame(1, $this->succeededPayments($invoice), 'the same charge was recorded twice');
    }

    /** The guard must not get in the way of the ordinary, uncontested charge. */
    #[Test]
    public function an_uncontested_charge_is_recorded_once_and_marks_the_invoice_paid(): void
    {
        $invoice = $this->invoice();

        app()->call([new ChargeSubscriptionInvoiceJob($invoice->fresh()), 'handle']);

        $this->assertSame(1, $this->succeededPayments($invoice));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }
}
