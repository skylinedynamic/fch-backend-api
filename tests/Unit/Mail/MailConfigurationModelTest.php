<?php

namespace Tests\Unit\Mail;

use App\Models\Setting\MailConfiguration;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One mail provider's settings, and what makes them verified and usable.
 * No database: these read the model's own rules.
 */
class MailConfigurationModelTest extends TestCase
{
    private function smtp(array $overrides = []): MailConfiguration
    {
        return new MailConfiguration(array_merge([
            'partner_id' => 7,
            'provider' => MailConfiguration::PROVIDER_SMTP,
            'from_address' => 'mail@acme.test',
            'bcc_emails' => null,
            'credentials' => [
                'smtp_host' => 'smtp.acme.test',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => 'mailer',
                'smtp_password' => 'secret',
            ],
        ], $overrides));
    }

    private function verified(MailConfiguration $config): MailConfiguration
    {
        $config->verified_at = Carbon::now();
        $config->verified_fingerprint = $config->fingerprint();

        return $config;
    }

    #[Test]
    public function a_configuration_is_verified_only_for_the_settings_it_was_verified_with(): void
    {
        $config = $this->verified($this->smtp());
        $this->assertTrue($config->isVerified());

        $config->credentials = array_merge($config->credentials, ['smtp_password' => 'changed']);

        $this->assertFalse($config->isVerified());
    }

    #[Test]
    public function changing_the_sender_address_needs_verifying_again(): void
    {
        $config = $this->verified($this->smtp());

        $config->from_address = 'other@acme.test';

        $this->assertFalse($config->isVerified());
    }

    #[Test]
    public function changing_only_the_bcc_list_keeps_it_verified(): void
    {
        $config = $this->verified($this->smtp());

        $config->bcc_emails = 'audit@acme.test';

        $this->assertTrue($config->isVerified());
    }

    #[Test]
    public function a_configuration_never_verified_is_not_verified(): void
    {
        $this->assertFalse($this->smtp()->isVerified());
    }

    #[Test]
    public function smtp_needs_every_connection_setting_to_be_usable(): void
    {
        $this->assertTrue($this->smtp()->isUsable());
        $this->assertFalse($this->smtp(['credentials' => ['smtp_host' => 'smtp.acme.test', 'smtp_username' => 'mailer']])->isUsable());
        $this->assertFalse($this->smtp(['from_address' => null])->isUsable());
    }

    #[Test]
    public function ses_needs_its_keys_and_region_to_be_usable(): void
    {
        $ses = new MailConfiguration([
            'provider' => MailConfiguration::PROVIDER_SES,
            'from_address' => 'mail@acme.test',
            'credentials' => ['aws_access_key' => 'AKIA', 'aws_secret_key' => 'secret', 'aws_region' => 'us-east-1'],
        ]);
        $this->assertTrue($ses->isUsable());

        $ses->credentials = ['aws_access_key' => 'AKIA', 'aws_region' => 'us-east-1'];
        $this->assertFalse($ses->isUsable());
    }

    #[Test]
    public function the_settings_handed_to_the_mailer_carry_the_provider_and_its_fields(): void
    {
        $settings = $this->smtp(['bcc_emails' => 'audit@acme.test'])->toMailSettings();

        $this->assertSame('smtp', $settings->email_provider);
        $this->assertSame('smtp.acme.test', $settings->smtp_host);
        $this->assertSame('secret', $settings->smtp_password);
        $this->assertSame('mail@acme.test', $settings->from_address);
        $this->assertSame('audit@acme.test', $settings->bcc_emails);
    }

    #[Test]
    public function credentials_are_never_serialised(): void
    {
        $this->assertArrayNotHasKey('credentials', $this->smtp()->toArray());
    }
}
