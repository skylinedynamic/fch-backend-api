<?php

namespace Tests\Feature\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * The unattended half of the payment system: the twice-daily auto-charge cron.
 *
 * It matters more than the interactive path, not less. An interactive failure is
 * a borrower seeing an error and telling someone; a cron failure is silent, and
 * it runs against every subscriber on a schedule. The same wrong-account bug sat
 * on this path too, because it resolves its gateway through the same
 * PaymentGatewayManager.
 *
 * It also charges a card nobody re-enters - `stripe_customer_id` saved months
 * earlier, off-session. So a partner switching mode strands every saved card at
 * once: they live in the old account and cannot be charged from the new one.
 * Failing loudly on that beats a Stripe error naming a customer.
 *
 * Stripe's HTTP layer is stubbed; the Authorization header is what proves which
 * account a charge would have reached.
 */
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
class SubscriptionAutoChargeModeTest extends TestCase
{
    use RefreshDatabase;

    private const SK_LIVE = 'sk_live_autocharge';
    private const SK_TEST = 'sk_test_autocharge';

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
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function configureStripe(string $mode): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_autocharge', 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => 'pk_live_autocharge', 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    /** A payer whose saved card belongs to the given Stripe account. */
    private function payerWithSavedCard(?string $storedMode): Partner
    {
        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => array_filter([
                'customer_id' => 'cus_saved',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
                'payment_mode' => $storedMode,
            ], fn ($v) => $v !== null),
        ]);

        return $this->host->fresh();
    }

    private function unpaidInvoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-AUTO-1',
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'customer_name' => 'Host Owner',
            'email' => 'host@example.test',
            'payment_amount' => 199.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_PLATFORM_FEE,
        ]);
    }

    private function runJob(Invoice $invoice): void
    {
        app()->call([new ChargeSubscriptionInvoiceJob($invoice), 'handle']);
    }

    /**
     * The ordinary case: saved card and partner agree on the account, so the
     * charge goes out on that account's key.
     */
    #[Test]
    public function it_auto_charges_with_the_configured_modes_key(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);
        $this->payerWithSavedCard(PaymentProvider::MODE_PRODUCTION);

        $this->runJob($this->unpaidInvoice());

        $this->assertSame([self::SK_LIVE], $this->http->apiKeysUsed());
    }

    /**
     * The guard. A card saved against the sandbox account cannot be charged from
     * the production one - it does not exist there. Refusing before contacting
     * Stripe turns a confusing gateway error into a statement of what is wrong.
     */
    #[Test]
    public function it_refuses_a_saved_card_from_the_other_account(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);
        $this->payerWithSavedCard(PaymentProvider::MODE_SANDBOX);

        $invoice = $this->unpaidInvoice();
        $this->runJob($invoice);

        $this->assertSame([], $this->http->apiKeysUsed(), 'Stripe was contacted with a card from the wrong account');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * The refusal has to say which mismatch it was. The whole reason this bug
     * took so long to find is that the original error named a token and said
     * nothing about modes.
     */
    #[Test]
    public function the_refusal_records_both_modes(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);
        $this->payerWithSavedCard(PaymentProvider::MODE_SANDBOX);

        $this->runJob($this->unpaidInvoice());

        $failure = Payment::where('status', Payment::STATUS_FAILED)->latest('id')->first();

        $this->assertNotNull($failure, 'no failed payment was recorded for the refusal');
        $this->assertStringContainsString(PaymentProvider::MODE_SANDBOX, (string) json_encode($failure->toArray()));
        $this->assertStringContainsString(PaymentProvider::MODE_PRODUCTION, (string) json_encode($failure->toArray()));
    }

    /**
     * Cards saved before the mode was recorded carry none, and must keep being
     * chargeable - refusing them all would stop billing for every existing
     * subscriber the day this shipped.
     */
    #[Test]
    public function a_card_saved_before_modes_were_recorded_is_still_charged(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);
        $this->payerWithSavedCard(null);

        $this->runJob($this->unpaidInvoice());

        $this->assertSame([self::SK_LIVE], $this->http->apiKeysUsed());
    }

    /**
     * Sandbox is symmetric: a test-account card is charged on the test account,
     * never promoted to live.
     */
    #[Test]
    public function a_sandbox_partner_auto_charges_on_the_test_account(): void
    {
        $this->configureStripe(PaymentProvider::MODE_SANDBOX);
        $this->payerWithSavedCard(PaymentProvider::MODE_SANDBOX);

        $this->runJob($this->unpaidInvoice());

        $this->assertSame([self::SK_TEST], $this->http->apiKeysUsed());
    }
}
