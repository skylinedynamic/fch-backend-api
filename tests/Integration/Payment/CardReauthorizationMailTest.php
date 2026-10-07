<?php

namespace Tests\Integration\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Mail\Payment\CardReauthorizationRequiredMail;
use App\Models\Borrower\Borrower;
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
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Telling a payer their saved card has to be entered again.
 *
 * A Stripe Customer and its card exist in exactly one account - test or live -
 * and Stripe provides no way to move them between the two. So when a partner
 * switches mode, every card already on file is unusable, and no retry will ever
 * succeed: only the payer entering it again resolves it.
 *
 * Nothing used to say so. The cron simply failed twice a day, writing ledger rows
 * nobody reads, until a month of unpaid invoices turned up.
 *
 * Two things have to be right here beyond the message going out at all. It must
 * be sent ONCE, because this job runs twice daily and repeating it would train
 * the payer to ignore it. And it must be sent from the right tenant's mailbox:
 * only a White-Label partner corresponds under its own name, while a Certified
 * partner trades under the host's brand. The cron has no authenticated user and
 * no request Origin, so the usual helper would answer "host" for everyone.
 */
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
class CardReauthorizationMailTest extends TestCase
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

        $this->configureStripe($this->host, PaymentProvider::MODE_PRODUCTION);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function configureStripe(Partner $partner, string $mode): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($partner->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_mail', 'secret_key' => 'sk_test_mail'],
                'production' => ['publishable_key' => 'pk_live_mail', 'secret_key' => 'sk_live_mail'],
            ],
        ]);
    }

    /** A partner with a card saved against the sandbox account. */
    private function strandedPartner(): Partner
    {
        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_from_sandbox',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ],
        ]);

        return $this->host->fresh();
    }

    private function invoiceFor(Partner $payer, string $reference = 'INV-MAIL-1'): Invoice
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
        Invoice::where('id', $invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);

        app()->call([new ChargeSubscriptionInvoiceJob($invoice->fresh()), 'handle']);
    }

    #[Test]
    public function the_payer_is_asked_to_re_enter_their_card(): void
    {
        $payer = $this->strandedPartner();

        $this->runCron($this->invoiceFor($payer));

        Mail::assertQueued(
            CardReauthorizationRequiredMail::class,
            fn ($mail) => $mail->hasTo($payer->email),
        );
    }

    #[Test]
    public function the_payer_is_also_asked_in_their_bell_with_a_link_to_their_billing_page(): void
    {
        Notification::fake();
        $payer = $this->strandedPartner();
        $login = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Host Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $payer->id,
            'partner_id' => $payer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->runCron($this->invoiceFor($payer));

        Notification::assertSentTo($login, DynamicNotification::class, fn (DynamicNotification $n) => $n->typeKey === 'partner.payment.card_reauthorization_required'
            && $n->toArray($login)['url'] === '/my-subscription');
    }

    /**
     * The whole reason a "first failure" marker exists. Twice a day, every day,
     * would make the message worthless.
     */
    #[Test]
    public function the_request_is_sent_once_however_often_the_cron_runs(): void
    {
        $payer = $this->strandedPartner();
        $invoice = $this->invoiceFor($payer);

        $this->runCron($invoice);
        $this->runCron($invoice);
        $this->runCron($invoice);

        // Counted by class: markFailed() queues its own cron-failure alert on
        // every pass, so a total across all mailables would say nothing here.
        Mail::assertQueued(CardReauthorizationRequiredMail::class, 1);
    }

    /**
     * Each unpaid invoice is its own request, though - silence on a second debt
     * would be a different bug.
     */
    #[Test]
    public function a_separate_invoice_gets_its_own_request(): void
    {
        $payer = $this->strandedPartner();

        $this->runCron($this->invoiceFor($payer, 'INV-MAIL-A'));
        $this->runCron($this->invoiceFor($payer, 'INV-MAIL-B'));

        Mail::assertQueued(CardReauthorizationRequiredMail::class, 2);
    }

    /**
     * A card that simply declined is a different story - it may well work next
     * time, and asking for a new card would be wrong.
     */
    #[Test]
    public function an_ordinary_decline_does_not_ask_for_a_new_card(): void
    {
        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_live',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
                'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            ],
        ]);

        $this->http->willReturn('payment_intents', [
            'id' => 'pi_declined',
            'object' => 'payment_intent',
            'status' => 'requires_payment_method',
        ]);

        $this->runCron($this->invoiceFor($this->host->fresh()));

        Mail::assertNotQueued(CardReauthorizationRequiredMail::class);
    }

    /**
     * A partner's own fees are billed by the host, so the host addresses them.
     */
    #[Test]
    public function a_partner_is_written_to_from_the_hosts_mailbox(): void
    {
        $payer = $this->strandedPartner();

        $this->runCron($this->invoiceFor($payer));

        Mail::assertQueued(
            CardReauthorizationRequiredMail::class,
            fn ($mail) => $mail->paymentData['partner_id'] === $this->host->id,
        );
    }

    /**
     * A White-Label partner corresponds under its own name, so its borrower is
     * written to from ITS mailbox - not the host's.
     */
    #[Test]
    public function a_white_label_partners_borrower_is_written_to_from_that_partner(): void
    {
        $whiteLabel = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'White Label LLC',
            'full_name' => 'WL Owner',
            'email' => 'wl@example.test',
        ]);

        $this->configureStripe($whiteLabel, PaymentProvider::MODE_PRODUCTION);

        $borrower = $this->strandedBorrowerOf($whiteLabel);

        $this->runCron($this->borrowerInvoice($borrower, $whiteLabel));

        Mail::assertQueued(
            CardReauthorizationRequiredMail::class,
            fn ($mail) => $mail->paymentData['partner_id'] === $whiteLabel->id,
        );
    }

    /**
     * A Certified partner trades under the host's brand, so its borrower hears
     * from the host instead.
     */
    #[Test]
    public function a_certified_partners_borrower_is_written_to_from_the_host(): void
    {
        $certified = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Certified LLC',
            'full_name' => 'Certified Owner',
            'email' => 'certified@example.test',
        ]);

        $this->configureStripe($certified, PaymentProvider::MODE_PRODUCTION);

        $borrower = $this->strandedBorrowerOf($certified);

        $this->runCron($this->borrowerInvoice($borrower, $certified));

        Mail::assertQueued(
            CardReauthorizationRequiredMail::class,
            fn ($mail) => $mail->paymentData['partner_id'] === $this->host->id,
        );
    }

    private function strandedBorrowerOf(Partner $partner): Borrower
    {
        return Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'A',
            'last_name' => 'Borrower',
            'email' => 'borrower-' . $partner->id . '@example.test',
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_from_sandbox',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ],
        ]);
    }

    private function borrowerInvoice(Borrower $borrower, Partner $partner): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-MAIL-B' . $borrower->id,
            'partner_id' => $partner->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'customer_name' => 'A Borrower',
            'email' => $borrower->email,
            'payment_amount' => 299.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
        ]);
    }
}
