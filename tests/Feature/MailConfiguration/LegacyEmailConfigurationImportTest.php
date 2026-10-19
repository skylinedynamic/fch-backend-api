<?php

namespace Tests\Feature\MailConfiguration;

use App\Models\Setting\MailConfiguration;
use App\Support\Mail\LegacyEmailConfigurationImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Moving the old key-value `email_configurations` into one row per provider.
 *
 * Nothing moved is verified - the fallback, made from the host's current
 * settings, included. Every configuration, the fallback too, is only trusted
 * once a code sent through it came back.
 */
class LegacyEmailConfigurationImportTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function legacy(?int $partnerId, array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('email_configurations')->insert([
                'partner_id' => $partnerId,
                'key' => $key,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function smtpAndSes(string $provider, string $enabled = '1'): array
    {
        return [
            'enable_custom_configuration' => $enabled,
            'email_provider' => $provider,
            'smtp_host' => 'smtp.acme.test',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'mailer',
            'smtp_password' => 'smtp-secret',
            'aws_access_key' => 'AKIAACME',
            'aws_secret_key' => 'ses-secret',
            'aws_region' => 'us-east-1',
            'from_address' => 'mail@acme.test',
            'bcc_emails' => 'audit@acme.test',
            'is_active' => '1',
        ];
    }

    private function import(): void
    {
        app(LegacyEmailConfigurationImporter::class)->run();
    }

    #[Test]
    public function each_provider_a_partner_filled_in_becomes_its_own_configuration(): void
    {
        $this->legacy($this->whiteLabel->id, $this->smtpAndSes('aws_ses'));

        $this->import();

        $rows = MailConfiguration::where('partner_id', $this->whiteLabel->id)->get()->keyBy('provider');
        $this->assertCount(2, $rows);
        $this->assertSame('smtp-secret', $rows['smtp']->credentials['smtp_password']);
        $this->assertSame(587, $rows['smtp']->credentials['smtp_port']);
        $this->assertSame('ses-secret', $rows['aws_ses']->credentials['aws_secret_key']);
        $this->assertSame('audit@acme.test', $rows['smtp']->bcc_emails);
    }

    #[Test]
    public function the_provider_that_was_switched_on_becomes_the_default_unverified(): void
    {
        $this->legacy($this->whiteLabel->id, $this->smtpAndSes('aws_ses'));

        $this->import();

        $rows = MailConfiguration::where('partner_id', $this->whiteLabel->id)->get()->keyBy('provider');
        $this->assertTrue($rows['aws_ses']->is_default);
        $this->assertFalse($rows['smtp']->is_default);
        $this->assertFalse($rows['aws_ses']->isVerified());
        $this->assertFalse($rows['smtp']->isVerified());
    }

    #[Test]
    public function a_partner_with_custom_mail_switched_off_gets_no_default(): void
    {
        $this->legacy($this->whiteLabel->id, $this->smtpAndSes('smtp', enabled: '0'));

        $this->import();

        $this->assertSame(0, MailConfiguration::where('partner_id', $this->whiteLabel->id)->where('is_default', true)->count());
    }

    #[Test]
    public function blank_or_placeholder_providers_are_not_moved(): void
    {
        $this->legacy($this->whiteLabel->id, array_merge($this->smtpAndSes('smtp'), [
            'aws_access_key' => 'your_aws_access_key',
            'aws_secret_key' => 'your_aws_secret_key',
        ]));
        $this->legacy(999, ['enable_custom_configuration' => '0', 'email_provider' => 'smtp', 'smtp_host' => '']);

        $this->import();

        $this->assertSame(['smtp'], MailConfiguration::where('partner_id', $this->whiteLabel->id)->pluck('provider')->all());
        $this->assertSame(0, MailConfiguration::where('partner_id', 999)->count());
    }

    #[Test]
    public function the_hosts_current_settings_become_the_fallback_unverified(): void
    {
        $this->legacy($this->host->id, $this->smtpAndSes('smtp'));

        $this->import();

        $fallback = MailConfiguration::whereNull('partner_id')->sole();
        $this->assertSame('smtp', $fallback->provider);
        $this->assertSame('smtp.acme.test', $fallback->credentials['smtp_host']);
        // The host verifies it with a code like any other configuration.
        $this->assertFalse($fallback->isVerified());
        $this->assertNull($fallback->verified_at);
        // The only fallback provider, so the one it sends with once verified.
        $this->assertTrue($fallback->is_default);

        // The host's own settings are moved like anyone else's, unverified.
        $this->assertFalse(MailConfiguration::where('partner_id', $this->host->id)->where('provider', 'smtp')->sole()->isVerified());
    }

    #[Test]
    public function the_host_does_not_need_custom_mail_switched_on_to_seed_the_fallback(): void
    {
        $this->legacy($this->host->id, $this->smtpAndSes('aws_ses', enabled: '0'));

        $this->import();

        $this->assertSame('aws_ses', MailConfiguration::whereNull('partner_id')->sole()->provider);
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->legacy($this->host->id, $this->smtpAndSes('smtp'));
        $this->legacy($this->whiteLabel->id, $this->smtpAndSes('aws_ses'));

        $this->import();
        $this->import();

        $this->assertSame(1, MailConfiguration::whereNull('partner_id')->count());
        $this->assertSame(2, MailConfiguration::where('partner_id', $this->whiteLabel->id)->count());
    }

    #[Test]
    public function without_usable_host_settings_there_is_no_fallback(): void
    {
        $this->legacy($this->whiteLabel->id, $this->smtpAndSes('smtp'));

        $this->import();

        $this->assertSame(0, MailConfiguration::whereNull('partner_id')->count());
    }
}
