<?php

namespace Tests\Feature\Payment;

use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A charge reaches the Stripe account the partner is configured for.
 *
 * This is the production failure proven at the level where it did the damage.
 * A partner set Stripe to Production; the browser was handed the live
 * publishable key and tokenized a card against the live account; the server then
 * initialized its gateway from a `payment_mode` it read out of the wrong place,
 * defaulted to sandbox, and charged the test account. Stripe answered "No such
 * PaymentMethod" for a token it had genuinely never issued.
 *
 * The assertion that catches that is not the returned PaymentIntent - a stubbed
 * Stripe returns one happily either way. It is the Authorization header: Stripe's
 * SDK sends the secret key on every request, so the key that carried the charge
 * names the account it landed on.
 *
 * These go through the real save service rather than writing linkages by hand,
 * because saving is what strips the inline `payment_mode` the broken readers
 * depended on - a hand-seeded row would not reproduce the bug.
 */
#[CoversClass(PaymentGatewayManager::class)]
class StripeChargeUsesConfiguredModeTest extends TestCase
{
    use RefreshDatabase;

    private const PK_LIVE = 'pk_live_charge';
    private const SK_LIVE = 'sk_live_charge';
    private const PK_TEST = 'pk_test_charge';
    private const SK_TEST = 'sk_test_charge';

    private Partner $host;
    private PaymentProvider $stripe;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** Save through the real service, exactly as the settings screen does. */
    private function configureStripe(string $mode, ?array $configuration = null): PartnerPaymentProvider
    {
        return app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => $configuration ?? [
                'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    /** An unpaid enrollment-fee invoice the host partner owes. */
    private function invoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-TEST-1',
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

    private function charge(Invoice $invoice): array
    {
        $manager = app(PaymentGatewayManager::class);

        return $manager->driverFor($manager->resolvePartnerProvider($this->host->id))->charge([
            'amount' => $invoice->payment_amount,
            'currency' => 'USD',
            'invoice_id' => $invoice->id,
            'payment_token' => 'pm_tokenized_in_the_browser',
            'description' => 'Enrollment fee',
        ]);
    }

    /**
     * The regression. Production configured, production key on the wire.
     */
    #[Test]
    public function a_production_partner_is_charged_with_the_live_secret_key(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $result = $this->charge($this->invoice());

        $this->assertTrue($result['success']);
        $this->assertSame([self::SK_LIVE], $this->http->apiKeysUsed());
    }

    #[Test]
    public function a_sandbox_partner_is_charged_with_the_test_secret_key(): void
    {
        $this->configureStripe(PaymentProvider::MODE_SANDBOX);

        $result = $this->charge($this->invoice());

        $this->assertTrue($result['success']);
        $this->assertSame([self::SK_TEST], $this->http->apiKeysUsed());
    }

    /**
     * Stated as the invariant rather than the value: whatever mode is configured,
     * every single Stripe call in the charge must ride the same account's key.
     * One stray call on the other key is the bug in miniature.
     */
    #[Test]
    public function every_call_in_a_charge_uses_one_account(): void
    {
        foreach ([PaymentProvider::MODE_SANDBOX, PaymentProvider::MODE_PRODUCTION] as $mode) {
            $this->http = FakeStripeHttpClient::install();

            $this->configureStripe($mode);
            $this->charge($this->invoice()->fresh() ?? $this->invoice());

            $this->assertCount(1, $this->http->apiKeysUsed(), "more than one Stripe account was used in {$mode} mode");
        }
    }

    /**
     * The charge really does walk the Stripe flow, rather than short-circuiting
     * somewhere that would make the key assertions vacuous.
     */
    #[Test]
    public function the_charge_resolves_a_customer_and_creates_a_payment_intent(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $result = $this->charge($this->invoice());

        $this->assertTrue($this->http->called('customers'));
        $this->assertTrue($this->http->called('payment_intents'));
        $this->assertSame('pi_fake', $result['transaction_id']);
    }

    /**
     * The payer's saved customer records which account it belongs to, so a later
     * mode switch can tell it is stale instead of handing it to the wrong API.
     */
    #[Test]
    public function the_saved_customer_records_the_mode_it_was_created_under(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->charge($this->invoice());

        $stored = $this->host->fresh()->stripe_customer_id;

        $this->assertSame('cus_fake', $stored['customer_id']);
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $stored['payment_mode']);
        $this->assertSame(PaymentProvider::STRIPE, $this->host->fresh()->default_payment_method);
    }

    /**
     * The recovery the whole guard exists to enable.
     *
     * A partner who paid in sandbox and then switched to production has a
     * customer on file that the live account has never heard of. Rather than
     * failing, resolveCustomer() discards it and resolves a live one - and the
     * charge succeeds, because the card was just tokenized in the browser
     * against the live account and is attached to that new customer.
     */
    #[Test]
    public function a_customer_from_the_other_account_is_replaced_and_the_charge_succeeds(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_from_sandbox',
                'name' => 'Old Cardholder',
                'card_no' => '**** **** **** 1111',
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ],
        ]);

        $result = $this->charge($this->invoice());
        $stored = $this->host->fresh()->stripe_customer_id;

        $this->assertTrue($result['success']);
        $this->assertSame('cus_fake', $stored['customer_id'], 'the stale sandbox customer was left on file');
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $stored['payment_mode']);
        $this->assertSame([self::SK_LIVE], $this->http->apiKeysUsed());
    }

    /**
     * The replaced customer's card details must not be carried over - they
     * describe a card on an account this partner no longer transacts with, and
     * showing that last4 would misrepresent what is about to be charged.
     */
    #[Test]
    public function card_details_do_not_survive_a_change_of_customer(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_from_sandbox',
                'name' => 'Old Cardholder',
                'card_no' => '**** **** **** 1111',
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ],
        ]);

        $this->charge($this->invoice());
        $stored = $this->host->fresh()->stripe_customer_id;

        $this->assertNotSame('**** **** **** 1111', $stored['card_no']);
        $this->assertNotSame('Old Cardholder', $stored['name']);
    }

    /**
     * A row saved before the mode was recorded gains one by being charged, which
     * is what lets the off-session guard recognize it on a later mode switch.
     */
    #[Test]
    public function charging_backfills_the_mode_onto_a_legacy_record(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->host->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_fake',
                'name' => 'A Cardholder',
                'card_no' => '**** **** **** 4242',
            ],
        ]);

        $this->charge($this->invoice());
        $stored = $this->host->fresh()->stripe_customer_id;

        $this->assertSame('cus_fake', $stored['customer_id']);
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $stored['payment_mode']);
        $this->assertSame('**** **** **** 4242', $stored['card_no'], 'card details were discarded though the customer did not move');
    }

    /**
     * A partner switched to production without production credentials must be
     * refused, not quietly charged on the sandbox account that still has keys.
     */
    #[Test]
    public function a_mode_with_no_credentials_refuses_instead_of_falling_back(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION, [
            'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
            'production' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        $this->charge($this->invoice());
    }
}
