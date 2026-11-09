<?php

namespace Tests\Integration\Payment;

use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Mail\Payment\PaymentFailedMail;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Who is auto-charged, and on which of the receiver's payment methods.
 *
 * Rule (product owner): an automatic charge is a Stripe charge, so it is made
 * only when the PAYER's default payment method is Stripe. A payer with another
 * default, or none, is not charged: a failed payment is recorded and they are
 * told once, to pay by hand. The receiver's own default does not matter - the
 * charge goes through the receiver's active Stripe.
 *
 * Defect: the cron charged through the receiver's DEFAULT method. A receiver
 * whose default was Cash or Bank Transfer (or with no default, where whichever
 * came first was taken) got a "driver" for Cash, which threw outside the job's
 * error handling - no failed payment, no notice, no stop after six attempts,
 * retried twice a day for ever, and the payer's saved card never charged.
 */
#[CoversClass(ChargeSubscriptionInvoiceJob::class)]
#[CoversClass(UnifiedPaymentService::class)]
class AutoChargeDefaultMethodTest extends TestCase
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

    private function configure(Partner $partner, PaymentProvider $provider, bool $default): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($partner->id, [
            'payment_provider_id' => $provider->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => $default,
            'configuration' => $provider->is($this->stripe) ? [
                'sandbox' => ['publishable_key' => 'pk_test_dm', 'secret_key' => 'sk_test_dm'],
                'production' => ['publishable_key' => 'pk_live_dm', 'secret_key' => 'sk_live_dm_' . $partner->id],
            ] : [],
        ]);
    }

    /** A payer with a saved card, whose default payment method is $default. */
    private function savedCard(?string $default): array
    {
        return [
            'default_payment_method' => $default,
            'stripe_customer_id' => ['customer_id' => 'cus_saved', 'name' => 'A Cardholder', 'card_no' => '4242', 'payment_mode' => PaymentProvider::MODE_PRODUCTION],
        ];
    }

    /** A borrower of the white-label partner with an installment due to that partner. */
    private function borrowerInstallment(?string $borrowerDefault): Invoice
    {
        $borrower = $this->makeBorrower($this->partner, $this->savedCard($borrowerDefault) + ['status' => Borrower::STATUS_ACTIVE]);

        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'customer_name' => 'Bo Rower',
            'email' => $borrower->email,
            'payment_amount' => 416.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
            'is_partner_paying' => false,
        ]);
    }

    /** The white-label partner's platform fee to the host. */
    private function partnerPlatformFee(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'customer_name' => $this->partner->full_name,
            'email' => $this->partner->email,
            'payment_amount' => 299.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_PLATFORM_FEE,
        ]);
    }

    private function runAutoCharge(Invoice $invoice): void
    {
        app()->call([new ChargeSubscriptionInvoiceJob($invoice), 'handle']);
    }

    private function failure(Invoice $invoice): ?Payment
    {
        return Payment::where('invoice_id', $invoice->id)->where('status', Payment::STATUS_FAILED)->latest('id')->first();
    }

    // ── The receiver: its active Stripe, whatever its default ──────────

    #[Test]
    public function a_receiver_whose_default_is_cash_is_still_paid_through_its_stripe(): void
    {
        $this->configure($this->partner, $this->cash, default: true);
        $this->configure($this->partner, $this->stripe, default: false);

        $invoice = $this->borrowerInstallment(PaymentProvider::STRIPE);
        $this->runAutoCharge($invoice);

        $this->assertSame(['sk_live_dm_' . $this->partner->id], $this->http->apiKeysUsed());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    #[Test]
    public function the_host_is_paid_through_its_stripe_whatever_its_default(): void
    {
        $this->configure($this->host, $this->cash, default: true);
        $this->configure($this->host, $this->stripe, default: false);
        $this->partner->update($this->savedCard(PaymentProvider::STRIPE));

        $invoice = $this->partnerPlatformFee();
        $this->runAutoCharge($invoice);

        $this->assertSame(['sk_live_dm_' . $this->host->id], $this->http->apiKeysUsed());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    #[Test]
    public function a_receiver_with_no_stripe_is_recorded_as_a_failure_not_retried_for_ever(): void
    {
        $this->configure($this->partner, $this->cash, default: true);

        $invoice = $this->borrowerInstallment(PaymentProvider::STRIPE);
        $this->runAutoCharge($invoice);

        $this->assertSame([], $this->http->apiKeysUsed());
        $this->assertNotNull($this->failure($invoice), 'no failed payment was recorded, so it would be retried for ever');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    // ── The payer: only a Stripe default is auto-charged ────────────────

    #[Test]
    public function a_payer_whose_default_is_not_stripe_is_not_charged_and_is_told_once(): void
    {
        $this->configure($this->partner, $this->stripe, default: true);

        $invoice = $this->borrowerInstallment(PaymentProvider::CASH);
        $this->runAutoCharge($invoice);
        $this->runAutoCharge($invoice);

        $this->assertSame([], $this->http->apiKeysUsed(), 'a payer who chose Cash had their saved card charged');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertNotNull($this->failure($invoice));
        Mail::assertQueued(PaymentFailedMail::class, 1);
    }

    #[Test]
    public function a_payer_with_no_default_is_not_charged(): void
    {
        $this->configure($this->partner, $this->stripe, default: true);

        $invoice = $this->borrowerInstallment(null);
        $this->runAutoCharge($invoice);

        $this->assertSame([], $this->http->apiKeysUsed());
        $this->assertNotNull($this->failure($invoice));
    }

    // ── The borrower fee (partner's card, host's Stripe) ────────────────

    private function borrowerFee(): Invoice
    {
        $borrower = $this->makeBorrower($this->partner);

        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'payment_amount' => 800.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
        ]);
    }

    /** The card the partner paid the host with before - what the fee charge reuses. */
    private function partnerPaidByCardBefore(): void
    {
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
    }

    #[Test]
    public function the_borrower_fee_is_charged_on_the_hosts_stripe_whatever_the_hosts_default(): void
    {
        $this->configure($this->host, $this->cash, default: true);
        $this->configure($this->host, $this->stripe, default: false);
        $this->partner->update($this->savedCard(PaymentProvider::STRIPE));
        $this->partnerPaidByCardBefore();

        $fee = $this->borrowerFee();
        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($fee);

        $this->assertSame(['sk_live_dm_' . $this->host->id], $this->http->apiKeysUsed());
        $this->assertSame(Invoice::STATUS_PAID, $fee->fresh()->status);
    }

    /**
     * Defect: charging the fee the moment the borrower paid looked for the card
     * in the partner's past card payments, while the cron charges the card
     * saved on the partner (My Subscription). A partner that paid its own
     * enrollment in cash and then added a card failed at once and waited up to
     * 12 hours for the cron - its borrower locked meanwhile. Both now charge
     * the saved card, the same way.
     */
    #[Test]
    public function a_card_saved_in_my_subscription_pays_the_borrower_fee_at_once(): void
    {
        $this->configure($this->host, $this->stripe, default: true);
        $this->partner->update($this->savedCard(PaymentProvider::STRIPE));
        // No past card payment from the partner.

        $fee = $this->borrowerFee();
        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($fee);

        $this->assertSame(['sk_live_dm_' . $this->host->id], $this->http->apiKeysUsed());
        $this->assertSame(Invoice::STATUS_PAID, $fee->fresh()->status);
        $this->assertNull($this->failure($fee));
    }

    #[Test]
    public function the_borrower_fee_charged_at_once_and_by_the_cron_is_one_charge(): void
    {
        $this->configure($this->host, $this->stripe, default: true);
        $this->partner->update($this->savedCard(PaymentProvider::STRIPE));

        $fee = $this->borrowerFee();
        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($fee);
        $atOnce = $this->http->chargeIdempotencyKey();

        // The charge went through but its record was lost - the case the key is
        // for. The cron then finds the fee Unpaid and charges it again.
        Payment::where('invoice_id', $fee->id)->forceDelete();
        $fee->update(['status' => Invoice::STATUS_UNPAID]);
        FakeStripeHttpClient::uninstall();
        $this->http = FakeStripeHttpClient::install();
        $this->runAutoCharge($fee);

        $this->assertNotNull($atOnce);
        $this->assertSame($atOnce, $this->http->chargeIdempotencyKey(), 'a cron retry could debit the partner a second time');
    }

    #[Test]
    public function the_borrower_fee_is_not_charged_when_the_partners_default_is_not_stripe(): void
    {
        $this->configure($this->host, $this->stripe, default: true);
        $this->partner->update($this->savedCard(PaymentProvider::CASH));
        $this->partnerPaidByCardBefore();

        $fee = $this->borrowerFee();
        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($fee);

        $this->assertSame([], $this->http->apiKeysUsed(), 'a partner who chose Cash had their saved card charged');
        $this->assertSame(Invoice::STATUS_UNPAID, $fee->fresh()->status);
        $this->assertNotNull($this->failure($fee));
    }
}
