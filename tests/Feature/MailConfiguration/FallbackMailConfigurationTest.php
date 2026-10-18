<?php

namespace Tests\Feature\MailConfiguration;

use App\Mail\Setting\MailConfigurationVerificationMail;
use App\Models\Partner\Partner;
use App\Models\Setting\MailConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform-wide fallback mail configuration. Like a partner's own, it keeps
 * one configuration per provider, each verified on its own, and sends with its
 * verified default - so switching from SMTP to SES never throws the SMTP
 * settings away. Only the host partner's own account (entity_type Partner) can
 * see or change it.
 */
class FallbackMailConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    private const URL = '/api/v1/common/mail-configurations/fallback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
    }

    /**
     * @return array<string, mixed>
     */
    private function smtpPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'smtp',
            'from_address' => 'noreply@fch.test',
            'smtp_host' => 'smtp.fch.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'platform',
            'smtp_password' => 'platform-secret',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function sesPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'aws_ses',
            'from_address' => 'noreply@fch.test',
            'aws_access_key' => 'AKIAFCH',
            'aws_secret_key' => 'ses-secret',
            'aws_region' => 'us-east-1',
        ], $overrides);
    }

    /** Sends a code for one fallback provider and returns it. */
    private function codeFor(MailConfiguration $config): string
    {
        $code = null;

        Mail::fake();
        $this->postJson(self::URL . "/{$config->id}/send-code")->assertOk();
        Mail::assertSent(MailConfigurationVerificationMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    #[Test]
    public function the_host_partner_sets_the_fallback_for_everyone(): void
    {
        $this->actingAsUser($this->owner($this->host));

        $this->postJson(self::URL, $this->smtpPayload())
            ->assertOk()
            ->assertJsonPath('data.provider', 'smtp')
            ->assertJsonPath('data.smtp_password', '••••••••')
            ->assertJsonPath('data.is_verified', false);

        $fallback = MailConfiguration::whereNull('partner_id')->sole();
        $this->assertSame('platform-secret', $fallback->credentials['smtp_password']);
    }

    #[Test]
    public function saving_another_provider_keeps_the_first(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $smtp = $this->markVerified($this->smtpConfig(null, ['is_default' => true]));

        $this->postJson(self::URL, $this->sesPayload())
            ->assertOk()
            ->assertJsonPath('data.provider', 'aws_ses')
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.is_verified', false);

        $this->assertSame(2, MailConfiguration::whereNull('partner_id')->count());

        // The SMTP fallback is untouched: its settings, its verification, and
        // still the one mail is sent with.
        $smtp->refresh();
        $this->assertSame('smtp.fallback.test', $smtp->credentials['smtp_host']);
        $this->assertSame('secret', $smtp->credentials['smtp_password']);
        $this->assertTrue($smtp->isVerified());
        $this->assertTrue($smtp->is_default);
    }

    #[Test]
    public function saving_a_provider_again_updates_its_one_configuration(): void
    {
        $this->actingAsUser($this->owner($this->host));

        $this->postJson(self::URL, $this->smtpPayload())->assertOk();
        $this->postJson(self::URL, $this->sesPayload())->assertOk();
        $this->postJson(self::URL, $this->smtpPayload(['smtp_host' => 'smtp2.fch.test', 'smtp_password' => '••••••••']))->assertOk();

        $this->assertSame(2, MailConfiguration::whereNull('partner_id')->count());
        $smtp = MailConfiguration::whereNull('partner_id')->where('provider', 'smtp')->sole();
        $this->assertSame('smtp2.fch.test', $smtp->credentials['smtp_host']);
        $this->assertSame('platform-secret', $smtp->credentials['smtp_password']);
    }

    #[Test]
    public function the_host_partner_reads_every_provider_back(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $this->sesConfig(null);
        $this->smtpConfig(null);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data.configurations')
            ->assertJsonPath('data.configurations.0.provider', 'smtp')
            ->assertJsonPath('data.configurations.0.smtp_host', 'smtp.fallback.test')
            ->assertJsonPath('data.configurations.1.provider', 'aws_ses')
            ->assertJsonPath('data.providers.0.value', 'smtp');
    }

    #[Test]
    public function the_host_partner_is_told_which_provider_mail_goes_out_with(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $smtp = $this->smtpConfig(null, ['is_default' => true]);

        $this->getJson(self::URL)->assertJsonPath('data.sending_id', null);

        // The default is unverified, so the verified SES sends meanwhile.
        $ses = $this->markVerified($this->sesConfig(null));
        $this->getJson(self::URL)->assertJsonPath('data.sending_id', $ses->id);

        $this->markVerified($smtp);
        $this->getJson(self::URL)->assertJsonPath('data.sending_id', $smtp->id);
    }

    #[Test]
    public function the_host_partner_verifies_a_provider_with_a_code(): void
    {
        $owner = $this->actingAsUser($this->owner($this->host));
        $smtp = $this->smtpConfig(null);

        Mail::fake();
        $this->postJson(self::URL . "/{$smtp->id}/send-code")->assertOk();
        $code = null;
        Mail::assertSent(MailConfigurationVerificationMail::class, function ($mail) use (&$code, $owner) {
            $code = $mail->code;

            return $mail->hasTo($owner->email);
        });

        // The first verified fallback provider becomes the default.
        $this->postJson(self::URL . "/{$smtp->id}/verify", ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function a_second_verified_provider_waits_until_it_is_chosen(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $smtp = $this->markVerified($this->smtpConfig(null, ['is_default' => true]));
        $ses = $this->sesConfig(null);

        $this->postJson(self::URL . "/{$ses->id}/verify", ['code' => $this->codeFor($ses)])
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.is_default', false);

        $this->postJson(self::URL . "/{$ses->id}/default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($smtp->refresh()->is_default);
    }

    #[Test]
    public function an_unverified_provider_cannot_become_the_default(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $smtp = $this->markVerified($this->smtpConfig(null, ['is_default' => true]));
        $ses = $this->sesConfig(null);

        $this->postJson(self::URL . "/{$ses->id}/default")->assertStatus(422)->assertJsonValidationErrors('id');

        $this->assertTrue($smtp->refresh()->is_default);
    }

    #[Test]
    public function a_partners_configuration_is_not_reachable_as_the_fallback(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $own = $this->smtpConfig($this->host);

        $this->postJson(self::URL . "/{$own->id}/send-code")->assertNotFound();
        $this->postJson(self::URL . "/{$own->id}/verify", ['code' => '123456'])->assertNotFound();
        $this->postJson(self::URL . "/{$own->id}/default")->assertNotFound();
    }

    #[Test]
    public function a_fallback_configuration_is_not_reachable_as_the_partners_own(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $fallback = $this->markVerified($this->smtpConfig(null));

        $this->postJson("/api/v1/common/mail-configurations/{$fallback->id}/default")->assertNotFound();
        $this->deleteJson("/api/v1/common/mail-configurations/{$fallback->id}")->assertNotFound();
    }

    #[Test]
    public function only_the_host_partners_own_account_may_touch_it(): void
    {
        $certified = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Certified Co',
            'full_name' => 'Certified Owner',
            'email' => 'certified-' . uniqid() . '@example.test',
        ]);
        $fallback = $this->smtpConfig(null);

        foreach ([
            $this->employee($this->host),
            $this->owner($this->whiteLabel),
            $this->owner($certified),
        ] as $user) {
            $this->actingAsUser($user);

            $this->getJson(self::URL)->assertForbidden();
            $this->postJson(self::URL, $this->smtpPayload())->assertForbidden();
            $this->postJson(self::URL . "/{$fallback->id}/send-code")->assertForbidden();
            $this->postJson(self::URL . "/{$fallback->id}/verify", ['code' => '123456'])->assertForbidden();
            $this->postJson(self::URL . "/{$fallback->id}/default")->assertForbidden();
        }

        $this->assertSame('smtp.fallback.test', MailConfiguration::whereNull('partner_id')->sole()->credentials['smtp_host']);
    }

    #[Test]
    public function the_host_partner_is_told_it_can_manage_the_fallback(): void
    {
        $this->actingAsUser($this->owner($this->host));
        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.can_manage_fallback', true);

        $this->actingAsUser($this->employee($this->host));
        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.can_manage_fallback', false);
    }

    #[Test]
    public function partners_see_the_fallback_as_verified_while_any_of_its_providers_can_send(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $this->smtpConfig(null, ['is_default' => true]);

        $this->getJson('/api/v1/common/mail-configurations')
            ->assertJsonPath('data.fallback.is_configured', true)
            ->assertJsonPath('data.fallback.is_verified', false);

        $this->markVerified($this->sesConfig(null));

        $this->getJson('/api/v1/common/mail-configurations')
            ->assertJsonPath('data.fallback.is_verified', true);
    }

    #[Test]
    public function partners_are_told_which_address_the_fallback_sends_from(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));

        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.fallback.from_address', null);

        $this->smtpConfig(null, ['is_default' => true]);
        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.fallback.from_address', null);

        $this->markVerified(MailConfiguration::whereNull('partner_id')->sole());
        $this->getJson('/api/v1/common/mail-configurations')->assertJsonPath('data.fallback.from_address', 'mail@fallback.test');
    }
}
