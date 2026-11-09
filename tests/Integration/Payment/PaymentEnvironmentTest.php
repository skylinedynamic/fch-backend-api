<?php

namespace Tests\Integration\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Borrower\Borrower;
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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Which environment (sandbox / production) a payment is recorded in.
 *
 * Defect: no path that records a payment said which environment it was in,
 * so Payment::createRecord() guessed from the Stripe mode of the payment's
 * partner_id. A Cash payment into a Production Cash method was recorded as
 * Sandbox whenever that partner's Stripe was Sandbox or missing, and a white-
 * label partner's payment to the host took the partner's own Stripe mode
 * instead of the host's. The environment is the mode of the payment method the
 * money was taken with (or the attempt was made with).
 *
 * Every partner here has Stripe in the opposite mode to the method actually
 * used, so a payment that still followed Stripe would land in the wrong one.
 */
#[CoversClass(Payment::class)]
#[CoversClass(UnifiedPaymentService::class)]
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
class PaymentEnvironmentTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private PaymentProvider $cash;
    private PaymentProvider $stripe;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->http = FakeStripeHttpClient::install();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();

        $this->cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => true]);
        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function configure(Partner $partner, PaymentProvider $provider, string $mode, bool $default = false): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($partner->id, [
            'payment_provider_id' => $provider->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => $default,
            'configuration' => $provider->is($this->stripe) ? [
                'sandbox' => ['publishable_key' => 'pk_test_env', 'secret_key' => 'sk_test_env'],
                'production' => ['publishable_key' => 'pk_live_env', 'secret_key' => 'sk_live_env'],
            ] : [],
        ]);
    }

    /** The borrower's invoice to their white-label partner. */
    private function borrowerInvoice(User $borrowerUser): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrowerUser->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerUser->entity_id,
            'customer_name' => $borrowerUser->name,
            'email' => $borrowerUser->email,
            'payment_amount' => 300.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'is_partner_paying' => false,
        ]);
    }

    /** What the white-label partner owes the host. */
    private function partnerInvoice(string $type = Invoice::INVOICE_TYPE_PLATFORM_FEE, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'customer_name' => $this->partner->full_name,
            'email' => $this->partner->email,
            'payment_amount' => 199.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => $type,
        ], $attributes));
    }

    private function payCash(Invoice $invoice): void
    {
        app(UnifiedPaymentService::class)->processPayment([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->cash->id,
            'payment_token' => json_encode(['mode' => 'manual', 'transaction_number' => 'CASH-1']),
        ]);
    }

    private function payByCard(Invoice $invoice): void
    {
        app(UnifiedPaymentService::class)->processPayment([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->stripe->id,
            'payment_token' => 'pm_card_visa',
        ]);
    }

    private function payment(Invoice $invoice, string $status): Payment
    {
        return Payment::where('invoice_id', $invoice->id)->where('status', $status)->latest('id')->firstOrFail();
    }

    private function partnerHasSavedCard(string $mode): void
    {
        $this->partner->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => ['customer_id' => 'cus_saved', 'name' => 'A Cardholder', 'card_no' => '4242', 'payment_mode' => $mode],
        ]);
    }

    // ── Pay Now ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_cash_payment_takes_the_cash_methods_production_mode_not_stripes(): void
    {
        $this->configure($this->partner, $this->cash, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        $borrower = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($borrower);

        $invoice = $this->borrowerInvoice($borrower);
        $this->payCash($invoice);

        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($invoice, Payment::STATUS_PROCESSING)->is_environment);
    }

    #[Test]
    public function a_cash_payment_takes_the_cash_methods_sandbox_mode_not_stripes(): void
    {
        $this->configure($this->partner, $this->cash, PaymentProvider::MODE_SANDBOX, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_PRODUCTION);
        $borrower = $this->makeBorrowerUser($this->partner);
        $this->actingAsUser($borrower);

        $invoice = $this->borrowerInvoice($borrower);
        $this->payCash($invoice);

        $this->assertSame(PaymentProvider::ENVIRONMENT_SANDBOX, (int) $this->payment($invoice, Payment::STATUS_PROCESSING)->is_environment);
    }

    #[Test]
    public function a_partners_cash_payment_to_the_host_takes_the_hosts_cash_mode(): void
    {
        $this->configure($this->host, $this->cash, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $invoice = $this->partnerInvoice(Invoice::INVOICE_TYPE_ENROLLMENT);
        $this->payCash($invoice);

        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($invoice, Payment::STATUS_PROCESSING)->is_environment);
    }

    #[Test]
    public function a_partners_card_payment_to_the_host_takes_the_hosts_stripe_mode(): void
    {
        $this->configure($this->host, $this->stripe, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $invoice = $this->partnerInvoice(Invoice::INVOICE_TYPE_ENROLLMENT);
        $this->payByCard($invoice);

        $this->assertSame(['sk_live_env'], $this->http->apiKeysUsed());
        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($invoice, Payment::STATUS_SUCCEEDED)->is_environment);
    }

    #[Test]
    public function a_declined_card_is_recorded_in_the_mode_it_was_tried_in(): void
    {
        $this->configure($this->host, $this->stripe, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->http->willFail('payment_intents', 402, [
            'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Your card was declined.',
        ]);

        $invoice = $this->partnerInvoice(Invoice::INVOICE_TYPE_ENROLLMENT);
        try {
            $this->payByCard($invoice);
            $this->fail('the declined payment was reported as a success');
        } catch (Exception) {
        }

        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($invoice, Payment::STATUS_FAILED)->is_environment);
    }

    // ── Auto-charge (cron) ───────────────────────────────────────────────────

    private function runAutoCharge(Invoice $invoice): void
    {
        app()->call([new ChargeSubscriptionInvoiceJob($invoice), 'handle']);
    }

    #[Test]
    public function an_auto_charge_takes_the_mode_of_the_stripe_account_it_charged(): void
    {
        $this->configure($this->host, $this->stripe, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        $this->partnerHasSavedCard(PaymentProvider::MODE_PRODUCTION);

        $invoice = $this->partnerInvoice();
        $this->runAutoCharge($invoice);

        $this->assertSame(['sk_live_env'], $this->http->apiKeysUsed());
        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($invoice, Payment::STATUS_SUCCEEDED)->is_environment);
    }

    #[Test]
    public function a_failed_auto_charge_takes_the_mode_of_the_account_it_would_have_charged(): void
    {
        $this->configure($this->host, $this->stripe, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        // No saved card: refused before Stripe is contacted.

        $invoice = $this->partnerInvoice();
        $this->runAutoCharge($invoice);

        $this->assertSame([], $this->http->apiKeysUsed());
        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($invoice, Payment::STATUS_FAILED)->is_environment);
    }

    // ── Borrower fee auto-charge (partner's card, host's Stripe) ────────────

    private function borrowerFeeInvoice(): Invoice
    {
        $borrower = $this->makeBorrowerUser($this->partner);

        return $this->partnerInvoice(Invoice::INVOICE_TYPE_BORROWER_FEE, [
            'borrower_id' => $borrower->entity_id,
            'payment_amount' => 800.00,
            'is_partner_paying' => true,
        ]);
    }

    #[Test]
    public function a_borrower_fee_auto_charge_takes_the_hosts_stripe_mode(): void
    {
        $this->configure($this->host, $this->stripe, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        $this->partnerHasSavedCard(PaymentProvider::MODE_PRODUCTION);
        // The card the partner paid the host with before.
        (new Payment())->createRecord([
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'amount' => 10,
            'payment_from' => PaymentProvider::STRIPE,
            'status' => Payment::STATUS_SUCCEEDED,
            'is_environment' => PaymentProvider::ENVIRONMENT_PRODUCTION,
            'details' => ['payment_method' => 'pm_saved', 'customer' => 'cus_saved'],
        ]);

        $fee = $this->borrowerFeeInvoice();
        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($fee);

        $this->assertSame(['sk_live_env'], $this->http->apiKeysUsed());
        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($fee, Payment::STATUS_SUCCEEDED)->is_environment);
    }

    #[Test]
    public function a_failed_borrower_fee_auto_charge_takes_the_hosts_stripe_mode(): void
    {
        $this->configure($this->host, $this->stripe, PaymentProvider::MODE_PRODUCTION, default: true);
        $this->configure($this->partner, $this->stripe, PaymentProvider::MODE_SANDBOX);
        // No card on file for the partner: refused before Stripe is contacted.

        $fee = $this->borrowerFeeInvoice();
        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($fee);

        $this->assertSame(PaymentProvider::ENVIRONMENT_PRODUCTION, (int) $this->payment($fee, Payment::STATUS_FAILED)->is_environment);
    }

    // ── The ledger itself ────────────────────────────────────────────────────

    #[Test]
    public function a_payment_cannot_be_recorded_without_saying_its_environment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Payment())->createRecord([
            'partner_id' => $this->partner->id,
            'amount' => 10,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }
}
