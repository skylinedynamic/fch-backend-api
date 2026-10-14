<?php

namespace Tests\Integration\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\Borrower\Billing\BorrowerBillingService;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A borrower saving the card their invoices will be charged to.
 *
 * This is the other way a Stripe Customer gets created - not by paying, but by
 * putting a card on file from the billing screen. It matters because it is the
 * only path that can repair a payer stranded by a mode switch: a Stripe Customer
 * lives in one account and cannot be moved between test and live, so once a
 * partner goes to production every card already on file is unusable and has to be
 * entered again. Re-entering here is what makes that work.
 *
 * Which means two things have to be right. The card must be attached using the
 * mode the partner is actually in, and the record written must say which account
 * it belongs to - without that, the off-session guard cannot tell later whether a
 * saved customer is still valid, and treats it as legacy and trusts it.
 *
 * Several real components meet here: the billing service, Payment's Stripe
 * configuration, the customer service, and the linkage model. Only Stripe's HTTP
 * layer is stubbed, which is also what lets these assert on the key that carried
 * each request.
 */
#[CoversClass(BorrowerBillingService::class)]
class BorrowerCardSaveTest extends TestCase
{
    use RefreshDatabase;

    private const SK_LIVE = 'sk_live_borrower';
    private const SK_TEST = 'sk_test_borrower';

    private Partner $partner;
    private Borrower $borrower;
    private PaymentProvider $stripe;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = FakeStripeHttpClient::install();

        // The borrower's own partner receives the money here, unlike a partner
        // fee, which is owed to the host.
        $this->partner = Partner::create([
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

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'A',
            'last_name' => 'Borrower',
            'email' => 'borrower@example.test',
        ]);

        $this->actingAs(User::create([
            'name' => 'A Borrower',
            'email' => 'borrower-user@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]));
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function configureStripe(string $mode): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_borrower', 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => 'pk_live_borrower', 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    private function saveCard(string $paymentMethodId = 'pm_tokenized_in_the_browser'): void
    {
        app(BorrowerBillingService::class)->updateDefaultPaymentMethod([
            'provider_name' => PaymentProvider::STRIPE,
            'payment_method_id' => $paymentMethodId,
        ]);
    }

    #[Test]
    public function saving_a_card_in_production_attaches_it_on_the_live_account(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->saveCard();

        $this->assertSame([self::SK_LIVE], $this->http->apiKeysUsed());
    }

    #[Test]
    public function saving_a_card_in_sandbox_attaches_it_on_the_test_account(): void
    {
        $this->configureStripe(PaymentProvider::MODE_SANDBOX);

        $this->saveCard();

        $this->assertSame([self::SK_TEST], $this->http->apiKeysUsed());
    }

    /**
     * Without the recorded mode the off-session guard cannot tell a stale
     * customer from a legacy one, and trusts it - so this is what makes a future
     * mode switch detectable rather than a run of mystery failures.
     */
    #[Test]
    public function the_saved_record_says_which_account_the_customer_belongs_to(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->saveCard();

        $stored = $this->borrower->fresh()->stripe_customer_id;

        $this->assertSame('cus_fake', $stored['customer_id']);
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $stored['payment_mode']);
        $this->assertSame(PaymentProvider::STRIPE, $this->borrower->fresh()->default_payment_method);
    }

    /**
     * The repair path. A borrower stranded by a mode switch re-enters their card;
     * the customer from the old account is discarded rather than reused, and the
     * record now points at the account the partner actually transacts in.
     */
    #[Test]
    public function re_entering_a_card_after_a_mode_switch_replaces_the_stranded_customer(): void
    {
        $this->borrower->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_from_sandbox',
                'name' => 'Old Cardholder',
                'card_no' => '**** **** **** 1111',
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ],
        ]);

        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);

        $this->saveCard();

        $stored = $this->borrower->fresh()->stripe_customer_id;

        $this->assertNotSame('cus_from_sandbox', $stored['customer_id'], 'the stranded customer was reused');
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $stored['payment_mode']);
        $this->assertSame([self::SK_LIVE], $this->http->apiKeysUsed());
    }

    /**
     * Switching to a non-Stripe provider clears the Stripe record entirely, so no
     * stale customer is left behind to be picked up later.
     */
    #[Test]
    public function choosing_another_provider_clears_the_stripe_record(): void
    {
        $this->configureStripe(PaymentProvider::MODE_PRODUCTION);
        $this->saveCard();
        // Bank transfer offered by the borrower's partner - only a gateway the
        // partner has switched on can be the default (findings O25).
        $bank = PaymentProvider::create(['name' => PaymentProvider::BANK_TRANSFER, 'display_name' => 'Bank Transfer', 'status' => true]);
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $bank->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'configuration' => [],
        ]);

        app(BorrowerBillingService::class)->updateDefaultPaymentMethod([
            'provider_name' => PaymentProvider::BANK_TRANSFER,
        ]);

        $borrower = $this->borrower->fresh();

        $this->assertSame(PaymentProvider::BANK_TRANSFER, $borrower->default_payment_method);
        $this->assertNull($borrower->stripe_customer_id);
    }
}
