<?php

namespace Tests\Feature\Setting;

use App\Models\Common\Permission;
use App\Models\Partner\Partner;
use App\Models\Setting\SmsConfiguration;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settings > SMS Configuration: each partner (host or white-label) keeps its
 * own Twilio account. The auth token is a credential: stored encrypted, never
 * returned, and a masked value sent back from the form keeps the stored one.
 */
class SmsConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/common/sms-configuration';
    private const PERMISSION = 'update sms configuration settings';
    private const ACCOUNT_SID = 'AC0123456789abcdef0123456789abcdef';

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Reseller Co',
            'full_name' => 'Reseller Owner',
            'email' => 'reseller@example.test',
        ]);
    }

    private function actAsPartnerAdmin(bool $withPermission = true): User
    {
        $user = User::create([
            'name' => 'Partner Admin',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->partner->id,
        ]);

        if ($withPermission) {
            Permission::create(['name' => self::PERMISSION, 'guard_name' => 'api', 'module' => 'settings']);
            $user->givePermissionTo(self::PERMISSION);
        }

        Passport::actingAs($user);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'USA',
            'provider' => 'twilio',
            'is_enabled' => true,
            'account_sid' => self::ACCOUNT_SID,
            'auth_token' => 'secret-token',
            'from_number' => '+15005550006',
            'messaging_service_sid' => null,
        ], $overrides);
    }

    #[Test]
    public function the_settings_need_the_sms_configuration_permission(): void
    {
        $this->actAsPartnerAdmin(withPermission: false);

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->validPayload())->assertForbidden();
        $this->postJson(self::URL . '/test', ['phone' => '+15005550006'])->assertForbidden();
    }

    #[Test]
    public function an_unconfigured_partner_gets_the_country_and_provider_options(): void
    {
        $this->actAsPartnerAdmin();

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.configuration', null)
            ->assertJsonPath('data.options.countries.0.value', 'USA')
            ->assertJsonPath('data.options.countries.0.label', 'United States')
            ->assertJsonPath('data.options.countries.1.value', 'CAN')
            ->assertJsonPath('data.options.countries.1.label', 'Canada')
            ->assertJsonPath('data.options.countries.0.providers.0.value', 'twilio');
    }

    #[Test]
    public function saving_stores_the_token_encrypted_and_never_returns_it(): void
    {
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.configuration.is_enabled', true)
            ->assertJsonPath('data.configuration.account_sid', self::ACCOUNT_SID)
            ->assertJsonPath('data.configuration.auth_token', '••••••••')
            ->assertJsonPath('data.configuration.from_number', '+15005550006');

        $config = SmsConfiguration::where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('secret-token', $config->credential('auth_token'));

        $raw = DB::table('sms_configurations')->where('partner_id', $this->partner->id)->value('credentials');
        $this->assertStringNotContainsString('secret-token', $raw);
    }

    #[Test]
    public function sending_back_the_masked_token_keeps_the_stored_one(): void
    {
        $this->actAsPartnerAdmin();
        $this->postJson(self::URL, $this->validPayload())->assertOk();

        $this->postJson(self::URL, $this->validPayload(['auth_token' => '••••••••', 'from_number' => '+15005550007']))
            ->assertOk();

        $config = SmsConfiguration::where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('secret-token', $config->credential('auth_token'));
        $this->assertSame('+15005550007', $config->from_number);
    }

    #[Test]
    public function without_partner_id_a_partner_edits_its_own_configuration(): void
    {
        // Naming another partner with `partner_id` is for Partner profile ›
        // Configuration (see PartnerConfigurationOnBehalfTest); who may do it is
        // decided by the admin panel, not checked here.
        $other = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Other Co',
            'full_name' => 'Other Owner',
            'email' => 'other@example.test',
        ]);
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload())->assertOk();

        $this->assertSame(1, SmsConfiguration::where('partner_id', $this->partner->id)->count());
        $this->assertSame(0, SmsConfiguration::where('partner_id', $other->id)->count());
    }

    #[Test]
    public function enabling_needs_complete_twilio_credentials(): void
    {
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload(['auth_token' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auth_token']);

        $this->postJson(self::URL, $this->validPayload(['from_number' => null, 'messaging_service_sid' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_number']);
    }

    #[Test]
    public function a_configuration_is_never_saved_with_empty_credentials_even_when_disabled(): void
    {
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload([
            'is_enabled' => false, 'account_sid' => null, 'auth_token' => null, 'from_number' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['account_sid', 'auth_token']);

        $this->assertSame(0, SmsConfiguration::count());
    }

    #[Test]
    public function a_complete_configuration_can_be_saved_disabled_and_switched_off_later(): void
    {
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload(['is_enabled' => false]))
            ->assertOk()
            ->assertJsonPath('data.configuration.is_enabled', false);

        // Switching a saved account off again: the masked token keeps the stored one.
        $this->postJson(self::URL, $this->validPayload(['is_enabled' => true]))->assertOk();
        $this->postJson(self::URL, $this->validPayload(['is_enabled' => false, 'auth_token' => '••••••••']))
            ->assertOk()
            ->assertJsonPath('data.configuration.is_enabled', false);
    }

    #[Test]
    public function only_offered_countries_providers_and_their_numbers_are_accepted(): void
    {
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload([
            'country' => 'BGD',
            'provider' => 'nexmo',
            'account_sid' => 'not-a-sid',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country', 'provider', 'account_sid']);

        $this->postJson(self::URL, $this->validPayload(['from_number' => '+8801700000000']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_number']);
    }

    #[Test]
    public function a_canadian_partner_can_configure_twilio_with_a_canadian_number(): void
    {
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL, $this->validPayload(['country' => 'CAN', 'from_number' => '+14165550123']))
            ->assertOk()
            ->assertJsonPath('data.configuration.country', 'CAN')
            ->assertJsonPath('data.configuration.from_number', '+14165550123');
    }

    #[Test]
    public function a_test_sms_goes_out_through_the_partners_own_twilio_account(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $this->actAsPartnerAdmin();
        $this->postJson(self::URL, $this->validPayload(['is_enabled' => false]))->assertOk();

        $this->postJson(self::URL . '/test', ['phone' => '+15005550009'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/Accounts/' . self::ACCOUNT_SID . '/Messages.json')
            && $request['To'] === '+15005550009'
            && $request['From'] === '+15005550006');
    }

    #[Test]
    public function a_rejected_test_sms_reports_the_providers_reason(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Authenticate'], 401)]);
        $this->actAsPartnerAdmin();
        $this->postJson(self::URL, $this->validPayload())->assertOk();

        $this->postJson(self::URL . '/test', ['phone' => '+15005550009'])
            ->assertUnprocessable()
            ->assertJsonPath('status', false)
            ->assertJsonFragment(['message' => __('sms_config.test_failed', ['reason' => 'Authenticate'])]);
    }

    #[Test]
    public function a_test_sms_needs_a_saved_configuration(): void
    {
        Http::fake();
        $this->actAsPartnerAdmin();

        $this->postJson(self::URL . '/test', ['phone' => '+15005550009'])->assertUnprocessable();

        Http::assertNothingSent();
    }
}
