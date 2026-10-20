<?php

namespace Tests\Feature\Payment;

use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payment-settings endpoints, entered as a request really enters them.
 *
 * Everything below goes through a route - middleware, the auth guard, the
 * controller, the form request and the resource - because that whole chain is
 * what the partner portal actually consumes. The seam between the save path and
 * the charge path is covered separately, in
 * Tests\Integration\Payment\PaymentModeResolutionTest.
 *
 * The symptom that started all of this was reported here: a partner set Stripe to
 * Production on the settings screen and the list kept showing Sandbox, because
 * the resource read `payment_mode` from a place the save path had just deleted it
 * from.
 */
class PaymentSettingsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const PK_LIVE = 'pk_live_endpoint';
    private const SK_LIVE = 'sk_live_endpoint';
    private const PK_TEST = 'pk_test_endpoint';
    private const SK_TEST = 'sk_test_endpoint';

    private Partner $host;
    private PaymentProvider $stripe;
    private User $partnerUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Saving Stripe keys asks Stripe whether they work (StripeKeyVerifier).
        // These keys are made up, so Stripe answers as it would for real ones.
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_endpoint']),
            'https://api.stripe.com/v1/tokens' => Http::response(['id' => 'tok_endpoint']),
            'https://api.stripe.com/v1/tokens/tok_endpoint' => Http::response(['id' => 'tok_endpoint']),
        ]);

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

        $this->partnerUser = User::create([
            'name' => 'Partner Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);

        Passport::actingAs($this->partnerUser);
    }

    /** Save through the real service, the way the settings screen does. */
    private function saveConfig(string $mode): PartnerPaymentProvider
    {
        return app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => $mode,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);
    }

    /**
     * The reported symptom: set Production, the list still said Sandbox.
     */
    #[Test]
    public function the_settings_list_reports_production_after_switching_to_production(): void
    {
        $this->saveConfig(PaymentProvider::MODE_PRODUCTION);

        $response = $this->getJson('/api/v1/common/partner-payment-providers');

        $response->assertOk();
        $this->assertSame(
            PaymentProvider::MODE_PRODUCTION,
            collect($response->json('data'))->firstWhere('name', PaymentProvider::STRIPE)['payment_mode']
        );
    }

    /**
     * The endpoint the payment forms read their publishable key from.
     */
    #[Test]
    public function the_payment_methods_endpoint_serves_the_live_key_in_production(): void
    {
        $this->saveConfig(PaymentProvider::MODE_PRODUCTION);

        $response = $this->getJson('/api/v1/payment/get-payment-methods');

        $response->assertOk();
        $this->assertSame(
            self::PK_LIVE,
            collect($response->json('data'))->firstWhere('name', PaymentProvider::STRIPE)['publishable_key']
        );
    }

    /**
     * The edit screen and the list screen must not disagree - them disagreeing is
     * what made the original report look like a display-only glitch.
     */
    #[Test]
    public function the_edit_screen_and_the_list_screen_agree(): void
    {
        $linkage = $this->saveConfig(PaymentProvider::MODE_PRODUCTION);

        $list = $this->getJson('/api/v1/common/partner-payment-providers');
        $detail = $this->getJson('/api/v1/common/partner-payment-providers/' . $this->stripe->id);

        $list->assertOk();
        $detail->assertOk();

        $this->assertSame(
            collect($list->json('data'))->firstWhere('name', PaymentProvider::STRIPE)['payment_mode'],
            $detail->json('data.payment_mode')
        );
        $this->assertSame(PaymentProvider::MODE_PRODUCTION, $detail->json('data.payment_mode'));
        $this->assertSame($linkage->id, $detail->json('data.id'));
    }

    /**
     * The guard that would have stopped this at the settings screen, exercised
     * through the form request that actually enforces it.
     */
    #[Test]
    public function the_api_refuses_test_keys_saved_under_production(): void
    {
        $response = $this->postJson('/api/v1/common/partner-payment-providers', [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'production' => [
                    'publishable_key' => 'pk_test_wrong_account',
                    'secret_key' => 'sk_test_wrong_account',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('partner_payment_providers', 0);
    }

    #[Test]
    public function the_api_accepts_correctly_paired_production_keys(): void
    {
        $response = $this->postJson('/api/v1/common/partner-payment-providers', [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => self::PK_TEST, 'secret_key' => self::SK_TEST],
                'production' => ['publishable_key' => self::PK_LIVE, 'secret_key' => self::SK_LIVE],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(
            PaymentProvider::MODE_PRODUCTION,
            PartnerPaymentProvider::first()->payment_mode
        );
    }
}
