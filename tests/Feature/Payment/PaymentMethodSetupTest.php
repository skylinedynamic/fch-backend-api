<?php

namespace Tests\Feature\Payment;

use App\Http\Resources\FCH\PaymentMethod\PartnerPaymentProviderListResource;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\PaymentMethod\PaymentMethodService;
use App\Support\Payment\PaymentSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A payment method is only offered to a payer once it can take a payment.
 *
 * Stripe switched on without usable keys for its mode used to be offered
 * anyway: the publishable key came back null, the card form waited for a
 * Stripe.js that was never going to load, and the payer watched "Initializing
 * secure payment gateway..." forever. Bank Transfer without a bank account
 * reached a form with nothing to pay into. Such a method is now left out of
 * the payer's list, and the partner's own settings list says what is missing.
 *
 * "Usable" is about the keys' shape, never about which mode they're saved
 * under: test keys under Production (a staging setup) still work, so they are
 * still offered. What can never work - a missing key, something that isn't a
 * Stripe key, or a test key paired with a live one - is not.
 */
#[CoversClass(PaymentSetup::class)]
#[CoversClass(PaymentMethodService::class)]
#[CoversClass(PartnerPaymentProviderListResource::class)]
#[CoversClass(\App\Http\Resources\FCH\PaymentMethod\PartnerPaymentProviderResource::class)]
class PaymentMethodSetupTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private PaymentProvider $stripe;
    private PaymentProvider $bank;
    private PaymentProvider $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
        $this->bank = PaymentProvider::create(['name' => PaymentProvider::BANK_TRANSFER, 'display_name' => 'Bank Transfer', 'status' => true]);
        $this->cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash Payment', 'status' => true]);

        Passport::actingAs(User::create([
            'name' => 'Partner Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]));
    }

    private function link(PaymentProvider $provider, string $mode = PaymentProvider::MODE_SANDBOX, ?array $configuration = null, bool $status = true): PartnerPaymentProvider
    {
        return PartnerPaymentProvider::create([
            'partner_id' => $this->host->id,
            'payment_provider_id' => $provider->id,
            'payment_mode' => $mode,
            'status' => $status,
            'is_default' => false,
            'configuration' => $configuration,
        ]);
    }

    private function stripeKeys(string $mode, ?string $publishable, ?string $secret): PartnerPaymentProvider
    {
        return $this->link($this->stripe, $mode, [$mode => ['publishable_key' => $publishable, 'secret_key' => $secret]]);
    }

    private function bankAccount(string $mode, bool $status = true): void
    {
        PaymentMethodBankDetail::create([
            'partner_id' => $this->host->id,
            'bank_name' => 'First Bank',
            'account_name' => 'Host Partner LLC',
            'account_number' => '000123456789',
            'payment_mode' => $mode,
            'is_default' => true,
            'status' => $status,
        ]);
    }

    /** The names the payer is offered. */
    private function offered(): array
    {
        return collect($this->getJson('/api/v1/payment/get-payment-methods')->assertOk()->json('data'))->pluck('name')->all();
    }

    /** The settings list's row for a provider. */
    private function settingsRow(PaymentProvider $provider): array
    {
        return collect($this->getJson('/api/v1/common/partner-payment-providers')->assertOk()->json('data'))
            ->firstWhere('id', $provider->id);
    }

    // ---- Stripe ---------------------------------------------------------------

    public static function workingStripeKeys(): array
    {
        return [
            'test keys in sandbox' => [PaymentProvider::MODE_SANDBOX, 'pk_test_abc123', 'sk_test_abc123'],
            'live keys in production' => [PaymentProvider::MODE_PRODUCTION, 'pk_live_abc123', 'sk_live_abc123'],
            'a restricted secret key' => [PaymentProvider::MODE_PRODUCTION, 'pk_live_abc123', 'rk_live_abc123'],
            // A staging setup: Stripe charges these on its test account, so they work.
            'test keys under production' => [PaymentProvider::MODE_PRODUCTION, 'pk_test_abc123', 'sk_test_abc123'],
            'live keys under sandbox' => [PaymentProvider::MODE_SANDBOX, 'pk_live_abc123', 'sk_live_abc123'],
        ];
    }

    #[Test]
    #[DataProvider('workingStripeKeys')]
    public function stripe_with_usable_keys_is_offered(string $mode, string $publishable, string $secret): void
    {
        $this->stripeKeys($mode, $publishable, $secret);

        $this->assertContains(PaymentProvider::STRIPE, $this->offered());
        $this->assertSame([], $this->settingsRow($this->stripe)['setup_issues']);
    }

    #[Test]
    public function the_offered_stripe_still_carries_its_publishable_key(): void
    {
        $this->stripeKeys(PaymentProvider::MODE_PRODUCTION, 'pk_live_abc123', 'sk_live_abc123');

        $stripe = collect($this->getJson('/api/v1/payment/get-payment-methods')->json('data'))->firstWhere('name', PaymentProvider::STRIPE);

        $this->assertSame('pk_live_abc123', $stripe['publishable_key']);
    }

    public static function unusableStripeKeys(): array
    {
        return [
            'no keys at all' => [null, null, ['publishable', 'secret']],
            'no secret key' => ['pk_test_abc123', null, ['secret']],
            'no publishable key' => [null, 'sk_test_abc123', ['publishable']],
            'a capitalised prefix' => ['Pk_test_abc123', 'sk_test_abc123', ['publishable']],
            'not a stripe key' => ['hello-world', 'FCFGii$8', ['publishable', 'secret']],
            'a secret key in the publishable field' => ['sk_test_abc123', 'sk_test_abc123', ['publishable']],
            'a publishable key in the secret field' => ['pk_test_abc123', 'pk_test_abc123', ['secret']],
            'an unknown key type as the secret' => ['pk_live_abc123', 'mk_1SPdbabc', ['secret']],
            'a test key paired with a live one' => ['pk_test_abc123', 'sk_live_abc123', ['mixed']],
        ];
    }

    #[Test]
    #[DataProvider('unusableStripeKeys')]
    public function stripe_without_usable_keys_is_not_offered_and_settings_say_why(?string $publishable, ?string $secret, array $problems): void
    {
        $this->stripeKeys(PaymentProvider::MODE_PRODUCTION, $publishable, $secret);

        $this->assertNotContains(PaymentProvider::STRIPE, $this->offered());

        $expected = array_map(fn (string $problem) => match ($problem) {
            'publishable' => __('payment_method.setup_publishable_key', ['mode' => 'Production']),
            'secret' => __('payment_method.setup_secret_key', ['mode' => 'Production']),
            'mixed' => __('payment_method.setup_keys_mixed', ['mode' => 'Production']),
        }, $problems);
        $this->assertSame($expected, $this->settingsRow($this->stripe)['setup_issues']);
    }

    #[Test]
    public function only_the_keys_of_the_active_mode_count(): void
    {
        // Production keys are complete, but the linkage runs in sandbox, whose keys are missing.
        $this->link($this->stripe, PaymentProvider::MODE_SANDBOX, [
            'production' => ['publishable_key' => 'pk_live_abc123', 'secret_key' => 'sk_live_abc123'],
        ]);

        $this->assertNotContains(PaymentProvider::STRIPE, $this->offered());
        $this->assertSame([
            __('payment_method.setup_publishable_key', ['mode' => 'Sandbox']),
            __('payment_method.setup_secret_key', ['mode' => 'Sandbox']),
        ], $this->settingsRow($this->stripe)['setup_issues']);
    }

    #[Test]
    public function legacy_flat_stripe_keys_still_count(): void
    {
        $this->link($this->stripe, PaymentProvider::MODE_SANDBOX, ['publishable_key' => 'pk_test_abc123', 'secret_key' => 'sk_test_abc123']);

        $this->assertContains(PaymentProvider::STRIPE, $this->offered());
    }

    // ---- Bank Transfer --------------------------------------------------------

    #[Test]
    public function bank_transfer_without_a_bank_account_is_not_offered_and_settings_say_why(): void
    {
        $this->link($this->bank);

        $this->assertNotContains(PaymentProvider::BANK_TRANSFER, $this->offered());
        $this->assertSame(
            [__('payment_method.setup_bank_account', ['mode' => 'Sandbox'])],
            $this->settingsRow($this->bank)['setup_issues']
        );
    }

    #[Test]
    public function bank_transfer_with_an_active_account_for_its_mode_is_offered(): void
    {
        $this->link($this->bank, PaymentProvider::MODE_PRODUCTION);
        $this->bankAccount(PaymentProvider::MODE_PRODUCTION);

        $this->assertContains(PaymentProvider::BANK_TRANSFER, $this->offered());
        $this->assertSame([], $this->settingsRow($this->bank)['setup_issues']);
    }

    #[Test]
    public function a_bank_account_for_the_other_mode_or_switched_off_does_not_count(): void
    {
        $this->link($this->bank, PaymentProvider::MODE_PRODUCTION);
        $this->bankAccount(PaymentProvider::MODE_SANDBOX);
        $this->bankAccount(PaymentProvider::MODE_PRODUCTION, status: false);

        $this->assertNotContains(PaymentProvider::BANK_TRANSFER, $this->offered());
    }

    // ---- Everything else ------------------------------------------------------

    #[Test]
    public function cash_needs_no_setup_and_is_always_offered(): void
    {
        $this->link($this->cash);

        $this->assertContains(PaymentProvider::CASH, $this->offered());
        $this->assertSame([], $this->settingsRow($this->cash)['setup_issues']);
    }

    #[Test]
    public function an_unusable_method_does_not_hide_the_others(): void
    {
        $this->stripeKeys(PaymentProvider::MODE_SANDBOX, null, null);
        $this->link($this->cash);

        $this->assertSame([PaymentProvider::CASH], $this->offered());
    }

    #[Test]
    public function the_settings_list_reports_no_issues_for_a_provider_never_set_up(): void
    {
        $row = $this->settingsRow($this->stripe);

        $this->assertFalse($row['status']);
        $this->assertSame([], $row['setup_issues']);
    }

    #[Test]
    public function the_settings_list_still_reports_issues_for_a_switched_off_provider(): void
    {
        // The settings screen only alerts on active ones, but the facts are there.
        $this->link($this->stripe, PaymentProvider::MODE_SANDBOX, null, status: false);

        $row = $this->settingsRow($this->stripe);

        $this->assertFalse($row['status']);
        $this->assertCount(2, $row['setup_issues']);
    }

    // ---- The method's own details page ----------------------------------------

    #[Test]
    public function the_details_page_reports_what_the_method_still_needs(): void
    {
        $this->stripeKeys(PaymentProvider::MODE_PRODUCTION, null, 'sk_live_abc123');

        $this->getJson('/api/v1/common/partner-payment-providers/' . $this->stripe->id)
            ->assertOk()
            ->assertJsonPath('data.setup_issues', [__('payment_method.setup_publishable_key', ['mode' => 'Production'])]);
    }

    #[Test]
    public function the_details_page_reports_nothing_when_the_method_is_ready(): void
    {
        $this->link($this->bank, PaymentProvider::MODE_SANDBOX);
        $this->bankAccount(PaymentProvider::MODE_SANDBOX);

        $this->getJson('/api/v1/common/partner-payment-providers/' . $this->bank->id)
            ->assertOk()
            ->assertJsonPath('data.setup_issues', []);
    }

    #[Test]
    public function the_details_page_of_a_method_never_set_up_reports_nothing(): void
    {
        $this->getJson('/api/v1/common/partner-payment-providers/' . $this->stripe->id)
            ->assertOk()
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.setup_issues', []);
    }

    #[Test]
    public function saving_answers_with_what_is_still_missing_after_the_save(): void
    {
        // Saving Stripe keys asks Stripe whether they work; answer as for real keys.
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/account' => Http::response(['id' => 'acct_123']),
            'https://api.stripe.com/v1/tokens' => Http::response(['id' => 'tok_123']),
            'https://api.stripe.com/v1/tokens/tok_123' => Http::response(['id' => 'tok_123']),
        ]);
        $save = fn (array $sandbox) => $this->postJson('/api/v1/common/partner-payment-providers', [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'configuration' => ['sandbox' => $sandbox],
        ])->assertOk();

        $save(['publishable_key' => 'pk_test_abc123', 'secret_key' => null])
            ->assertJsonPath('data.setup_issues', [__('payment_method.setup_secret_key', ['mode' => 'Sandbox'])]);

        $save(['publishable_key' => 'pk_test_abc123', 'secret_key' => 'sk_test_abc123'])
            ->assertJsonPath('data.setup_issues', []);
    }

    #[Test]
    public function switching_a_method_on_answers_with_what_it_still_needs(): void
    {
        $this->postJson('/api/v1/common/partner-payment-providers/status', [
            'payment_provider_id' => $this->bank->id,
            'status' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.setup_issues', [__('payment_method.setup_bank_account', ['mode' => 'Sandbox'])]);
    }

    // ---- Gateways paid by reference number -----------------------------------

    private function gateway(string $name): PaymentProvider
    {
        return PaymentProvider::create(['name' => $name, 'display_name' => $name, 'status' => true]);
    }

    public static function credentialGateways(): array
    {
        return [
            'Square' => [PaymentProvider::SQUARE, ['app_id' => 'Application ID', 'access_token' => 'Access Token']],
            'Authorize.Net' => [PaymentProvider::AUTHORIZE_NET, ['login_id' => 'API Login ID', 'transaction_key' => 'Transaction Key']],
            'Ecrypt' => [PaymentProvider::ECRYPT, ['client_key' => 'Client Key', 'client_secret' => 'Client Secret']],
        ];
    }

    #[Test]
    #[DataProvider('credentialGateways')]
    public function a_gateway_with_no_credentials_says_so_but_is_still_offered(string $name, array $fields): void
    {
        // Paid by reference number, so payers keep it; the partner is told what's missing.
        $provider = $this->gateway($name);
        $this->link($provider, PaymentProvider::MODE_SANDBOX, null);

        $this->assertContains($name, $this->offered());

        $expected = array_map(fn (string $label) => __('payment_method.setup_credential', ['mode' => 'Sandbox', 'field' => $label]), array_values($fields));
        $row = $this->settingsRow($provider);
        $this->assertSame($expected, $row['setup_issues']);
        $this->assertTrue($row['can_take_payments']);

        $this->getJson('/api/v1/common/partner-payment-providers/' . $provider->id)
            ->assertJsonPath('data.setup_issues', $expected)
            ->assertJsonPath('data.can_take_payments', true);
    }

    #[Test]
    #[DataProvider('credentialGateways')]
    public function a_gateway_names_only_the_credential_still_missing(string $name, array $fields): void
    {
        $provider = $this->gateway($name);
        [$first, $second] = array_keys($fields);
        $this->link($provider, PaymentProvider::MODE_PRODUCTION, ['production' => [$first => 'abc123', $second => '']]);

        $this->assertSame(
            [__('payment_method.setup_credential', ['mode' => 'Production', 'field' => $fields[$second]])],
            $this->settingsRow($provider)['setup_issues']
        );
    }

    #[Test]
    #[DataProvider('credentialGateways')]
    public function a_gateway_with_its_credentials_reports_nothing(string $name, array $fields): void
    {
        $provider = $this->gateway($name);
        $this->link($provider, PaymentProvider::MODE_SANDBOX, ['sandbox' => array_fill_keys(array_keys($fields), 'abc123')]);

        $this->assertSame([], $this->settingsRow($provider)['setup_issues']);
    }

    #[Test]
    public function a_gateways_credentials_saved_before_the_mode_split_still_count(): void
    {
        // The settings form still reads these flat, mode-suffixed names.
        $square = $this->gateway(PaymentProvider::SQUARE);
        $this->link($square, PaymentProvider::MODE_SANDBOX, ['square_app_id_sandbox' => 'abc', 'square_token_sandbox' => 'abc']);
        $authorize = $this->gateway(PaymentProvider::AUTHORIZE_NET);
        $this->link($authorize, PaymentProvider::MODE_PRODUCTION, ['authorize_login_id_production' => 'abc', 'authorize_transaction_key_production' => 'abc']);
        $ecrypt = $this->gateway(PaymentProvider::ECRYPT);
        $this->link($ecrypt, PaymentProvider::MODE_SANDBOX, ['client_key_sandbox' => 'abc', 'client_secret_sandbox' => 'abc']);

        $this->assertSame([], $this->settingsRow($square)['setup_issues']);
        $this->assertSame([], $this->settingsRow($authorize)['setup_issues']);
        $this->assertSame([], $this->settingsRow($ecrypt)['setup_issues']);
    }

    #[Test]
    public function only_the_active_modes_credentials_count_for_a_gateway(): void
    {
        $square = $this->gateway(PaymentProvider::SQUARE);
        $this->link($square, PaymentProvider::MODE_SANDBOX, ['production' => ['app_id' => 'abc', 'access_token' => 'abc']]);

        $this->assertCount(2, $this->settingsRow($square)['setup_issues']);
    }

    #[Test]
    public function the_settings_say_whether_payers_can_use_the_method(): void
    {
        $this->stripeKeys(PaymentProvider::MODE_SANDBOX, null, null);
        $this->link($this->cash);

        $this->assertFalse($this->settingsRow($this->stripe)['can_take_payments']);
        $this->assertTrue($this->settingsRow($this->cash)['can_take_payments']);
        $this->getJson('/api/v1/common/partner-payment-providers/' . $this->stripe->id)
            ->assertJsonPath('data.can_take_payments', false);
    }

    #[Test]
    public function a_method_never_set_up_is_not_said_to_take_payments(): void
    {
        $this->assertFalse($this->settingsRow($this->stripe)['can_take_payments']);
        $this->getJson('/api/v1/common/partner-payment-providers/' . $this->stripe->id)
            ->assertJsonPath('data.can_take_payments', false);
    }
}
