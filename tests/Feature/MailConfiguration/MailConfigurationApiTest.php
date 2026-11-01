<?php

namespace Tests\Feature\MailConfiguration;

use App\Models\Setting\MailConfiguration;
use App\Services\FCH\Common\Setting\MailConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settings > Email Configuration: a partner keeps one configuration per mail
 * provider, verifies each, and picks a verified one as its default.
 */
class MailConfigurationApiTest extends TestCase
{
    use MailConfigurationFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/common/mail-configurations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function smtpPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'smtp',
            'from_address' => 'mail@acme.test',
            'bcc_emails' => null,
            'smtp_host' => 'smtp.acme.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'mailer',
            'smtp_password' => 'smtp-secret',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function sesPayload(): array
    {
        return [
            'provider' => 'aws_ses',
            'from_address' => 'ses@acme.test',
            'aws_access_key' => 'AKIAACME',
            'aws_secret_key' => 'ses-secret',
            'aws_region' => 'us-east-1',
        ];
    }

    #[Test]
    public function the_settings_need_the_email_configuration_permission(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel, withPermission: false));

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->smtpPayload())->assertForbidden();
    }

    #[Test]
    public function saving_a_provider_stores_it_unverified_with_its_secret_encrypted(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));

        $this->postJson(self::URL, $this->smtpPayload())
            ->assertOk()
            ->assertJsonPath('data.provider', 'smtp')
            ->assertJsonPath('data.smtp_host', 'smtp.acme.test')
            ->assertJsonPath('data.has_smtp_password', true)
            ->assertJsonMissingPath('data.smtp_password')
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.is_default', false);

        $config = MailConfiguration::where('partner_id', $this->whiteLabel->id)->firstOrFail();
        $this->assertSame('smtp-secret', $config->credentials['smtp_password']);

        $raw = DB::table('mail_configurations')->where('id', $config->id)->value('credentials');
        $this->assertStringNotContainsString('smtp-secret', $raw);
        $this->assertStringNotContainsString('smtp.acme.test', $raw);
    }

    #[Test]
    public function a_partner_keeps_one_configuration_per_provider(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));

        $this->postJson(self::URL, $this->smtpPayload())->assertOk();
        $this->postJson(self::URL, $this->sesPayload())->assertOk();
        $this->postJson(self::URL, $this->smtpPayload(['smtp_host' => 'smtp2.acme.test']))->assertOk();

        $this->assertSame(2, MailConfiguration::where('partner_id', $this->whiteLabel->id)->count());
        $this->assertSame('smtp2.acme.test', MailConfiguration::where('partner_id', $this->whiteLabel->id)
            ->where('provider', 'smtp')->first()->credentials['smtp_host']);
    }

    #[Test]
    public function a_masked_or_empty_secret_keeps_the_stored_one(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $this->postJson(self::URL, $this->smtpPayload())->assertOk();

        $this->postJson(self::URL, $this->smtpPayload(['smtp_password' => '••••••••']))->assertOk();
        $this->postJson(self::URL, $this->smtpPayload(['smtp_password' => '']))->assertOk();

        $config = MailConfiguration::where('partner_id', $this->whiteLabel->id)->firstOrFail();
        $this->assertSame('smtp-secret', $config->credentials['smtp_password']);
    }

    /**
     * Defect (BUG-133): the settings list sent the stored AWS access key in
     * plain text, and each secret as a mask in its own field. A stored
     * credential is now only reported as has_<field>.
     */
    #[Test]
    public function the_list_never_sends_a_stored_credential_to_the_browser(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $this->postJson(self::URL, $this->smtpPayload())->assertOk();
        $this->postJson(self::URL, $this->sesPayload())->assertOk();

        $response = $this->getJson(self::URL)->assertOk();
        // The providers' form schema names the fields; the stored settings must not carry them.
        $raw = json_encode($response->json('data.configurations'), JSON_UNESCAPED_UNICODE);

        foreach (['smtp-secret', 'AKIAACME', 'ses-secret', MailConfigurationService::MASK] as $leak) {
            $this->assertStringNotContainsString($leak, $raw);
        }
        foreach (['smtp_password', 'aws_access_key', 'aws_secret_key'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $raw);
        }

        $configs = collect($response->json('data.configurations'))->keyBy('provider');
        $this->assertTrue($configs['smtp']['has_smtp_password']);
        $this->assertTrue($configs['aws_ses']['has_aws_access_key']);
        $this->assertTrue($configs['aws_ses']['has_aws_secret_key']);
        $this->assertSame('us-east-1', $configs['aws_ses']['aws_region']);
    }

    #[Test]
    public function saving_without_the_aws_keys_keeps_the_stored_ones(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $this->postJson(self::URL, $this->sesPayload())->assertOk();

        $this->postJson(self::URL, array_merge($this->sesPayload(), ['aws_access_key' => '', 'aws_secret_key' => '']))
            ->assertOk()
            ->assertJsonPath('data.has_aws_access_key', true)
            ->assertJsonMissingPath('data.aws_access_key');

        $config = MailConfiguration::where('partner_id', $this->whiteLabel->id)->where('provider', 'aws_ses')->firstOrFail();
        $this->assertSame('AKIAACME', $config->credentials['aws_access_key']);
        $this->assertSame('ses-secret', $config->credentials['aws_secret_key']);
    }

    #[Test]
    public function a_new_provider_needs_its_secret(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));

        $this->postJson(self::URL, $this->smtpPayload(['smtp_password' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('smtp_password');
    }

    #[Test]
    public function each_provider_validates_its_own_fields(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));

        $this->postJson(self::URL, ['provider' => 'smtp', 'from_address' => 'mail@acme.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username']);

        $this->postJson(self::URL, ['provider' => 'aws_ses', 'from_address' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_address', 'aws_region']);

        // The AWS keys are secrets: never given before, they are still required.
        $this->postJson(self::URL, ['provider' => 'aws_ses', 'from_address' => 'ses@acme.test', 'aws_region' => 'us-east-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['aws_access_key', 'aws_secret_key']);

        $this->postJson(self::URL, ['provider' => 'pigeon', 'from_address' => 'mail@acme.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider');
    }

    #[Test]
    public function changing_a_verified_providers_settings_unverifies_it(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $this->markVerified($this->smtpConfig($this->whiteLabel, ['is_default' => true]));

        $this->postJson(self::URL, $this->smtpPayload(['smtp_host' => 'smtp.new.test']))
            ->assertOk()
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.verified_at', null)
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function the_list_is_the_partners_own_providers_with_the_fallback_status(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $this->markVerified($this->smtpConfig(null));
        $this->smtpConfig($this->whiteLabel);
        $this->smtpConfig($this->host);

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertJsonCount(1, 'data.configurations');
        $response->assertJsonPath('data.configurations.0.has_smtp_password', true);
        $response->assertJsonMissingPath('data.configurations.0.smtp_password');
        $response->assertJsonPath('data.fallback.is_configured', true);
        $response->assertJsonPath('data.fallback.is_verified', true);
        $response->assertJsonPath('data.uses_own_configuration', false);
        $response->assertJsonPath('data.can_manage_fallback', false);
        $response->assertJsonPath('data.providers.0.value', 'smtp');
        $response->assertJsonPath('data.providers.1.value', 'aws_ses');
        // Nothing of the fallback's own settings reaches a partner.
        $this->assertStringNotContainsString('smtp.fallback.test', $response->getContent());
    }

    #[Test]
    public function only_a_verified_provider_can_be_the_default(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $smtp = $this->smtpConfig($this->whiteLabel);

        $this->postJson(self::URL."/{$smtp->id}/default")->assertUnprocessable();
        $this->assertFalse($smtp->refresh()->is_default);

        $this->markVerified($smtp);
        $this->postJson(self::URL."/{$smtp->id}/default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function a_partner_has_one_default(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $smtp = $this->markVerified($this->smtpConfig($this->whiteLabel, ['is_default' => true]));
        $ses = $this->markVerified($this->sesConfig($this->whiteLabel));

        $this->postJson(self::URL."/{$ses->id}/default")->assertOk();

        $this->assertTrue($ses->refresh()->is_default);
        $this->assertFalse($smtp->refresh()->is_default);
    }

    #[Test]
    public function a_provider_can_be_removed(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $smtp = $this->smtpConfig($this->whiteLabel, ['is_default' => true]);

        $this->deleteJson(self::URL."/{$smtp->id}")->assertOk();

        $this->assertNull(MailConfiguration::find($smtp->id));
    }

    #[Test]
    public function another_partners_configuration_cannot_be_touched(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));
        $hostSmtp = $this->markVerified($this->smtpConfig($this->host));
        $fallback = $this->markVerified($this->smtpConfig(null));

        foreach ([$hostSmtp, $fallback] as $config) {
            $this->postJson(self::URL."/{$config->id}/default")->assertNotFound();
            $this->postJson(self::URL."/{$config->id}/send-code")->assertNotFound();
            $this->postJson(self::URL."/{$config->id}/verify", ['code' => '123456'])->assertNotFound();
            $this->deleteJson(self::URL."/{$config->id}")->assertNotFound();
        }
    }

    #[Test]
    public function an_employee_with_the_permission_manages_their_partners_mail(): void
    {
        $this->actingAsUser($this->employee($this->whiteLabel));

        $this->postJson(self::URL, $this->smtpPayload())->assertOk();

        $this->assertSame(1, MailConfiguration::where('partner_id', $this->whiteLabel->id)->count());
    }

    #[Test]
    public function saving_or_removing_mail_settings_refreshes_the_partners_signed_in_users(): void
    {
        // Their portals re-read the "email set up" flag when told to refresh.
        $this->actingAsUser($this->owner($this->whiteLabel));
        $colleague = $this->employee($this->whiteLabel);
        $outsider = $this->owner($this->host);

        $this->postJson(self::URL, $this->smtpPayload())->assertOk();

        $this->assertTrue($colleague->refresh()->is_refresh);
        $this->assertFalse((bool) $outsider->refresh()->is_refresh);

        $colleague->update(['is_refresh' => false]);
        $config = MailConfiguration::where('partner_id', $this->whiteLabel->id)->firstOrFail();
        $this->deleteJson(self::URL."/{$config->id}")->assertOk();

        $this->assertTrue($colleague->refresh()->is_refresh);
    }

    #[Test]
    public function a_saved_configuration_counts_as_email_set_up(): void
    {
        $this->actingAsUser($this->owner($this->whiteLabel));

        $this->getJson('/api/v1/common/get-theme')->assertJsonPath('data.status_flags.is_fresh_email', null);

        $this->smtpConfig($this->whiteLabel);

        $this->getJson('/api/v1/common/get-theme')->assertJsonPath('data.status_flags.is_fresh_email', true);
    }
}
