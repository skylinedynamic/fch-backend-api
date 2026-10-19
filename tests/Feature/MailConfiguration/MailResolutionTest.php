<?php

namespace Tests\Feature\MailConfiguration;

use App\Services\FCH\Common\Setting\EmailConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which settings a partner's mail is sent with: its own default provider once
 * verified, otherwise the one platform-wide fallback, otherwise nothing.
 */
class MailResolutionTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    private EmailConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
        $this->service = app(EmailConfigurationService::class);
    }

    #[Test]
    public function a_verified_default_provider_sends_the_partners_mail(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->markVerified($this->smtpConfig($this->whiteLabel, ['is_default' => true]));

        $settings = $this->service->resolveFor($this->whiteLabel->id);

        $this->assertSame('smtp.partner' . $this->whiteLabel->id . '.test', $settings->smtp_host);
    }

    #[Test]
    public function an_unverified_default_provider_sends_through_the_fallback(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->smtpConfig($this->whiteLabel, ['is_default' => true]);

        $this->assertSame('smtp.fallback.test', $this->service->resolveFor($this->whiteLabel->id)->smtp_host);
    }

    #[Test]
    public function a_default_changed_after_verifying_sends_through_the_fallback(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $own = $this->markVerified($this->smtpConfig($this->whiteLabel, ['is_default' => true]));

        $own->credentials = array_merge($own->credentials, ['smtp_password' => 'changed']);
        $own->save();

        $this->assertSame('smtp.fallback.test', $this->service->resolveFor($this->whiteLabel->id)->smtp_host);
    }

    #[Test]
    public function a_verified_provider_that_is_not_the_default_is_not_used(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->smtpConfig($this->whiteLabel, ['is_default' => true]);
        $this->markVerified($this->sesConfig($this->whiteLabel));

        $this->assertSame('smtp.fallback.test', $this->service->resolveFor($this->whiteLabel->id)->smtp_host);
    }

    #[Test]
    public function the_default_can_be_ses(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->markVerified($this->sesConfig($this->whiteLabel, ['is_default' => true]));

        $settings = $this->service->resolveFor($this->whiteLabel->id);

        $this->assertSame('aws_ses', $settings->email_provider);
        $this->assertSame('AKIA' . $this->whiteLabel->id, $settings->aws_access_key);
    }

    #[Test]
    public function the_host_follows_the_same_rule(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->smtpConfig($this->host, ['is_default' => true]);

        $this->assertSame('smtp.fallback.test', $this->service->resolveFor($this->host->id)->smtp_host);
    }

    #[Test]
    public function mail_with_no_partner_goes_through_the_fallback(): void
    {
        $this->markVerified($this->smtpConfig(null));

        $this->assertSame('smtp.fallback.test', $this->service->resolveFor(null)->smtp_host);
    }

    #[Test]
    public function an_unverified_fallback_is_not_used(): void
    {
        $this->smtpConfig(null);
        $this->smtpConfig($this->whiteLabel, ['is_default' => true]);

        $this->assertNull($this->service->resolveFor($this->whiteLabel->id));
    }

    #[Test]
    public function the_fallback_sends_with_its_verified_default_while_a_new_provider_is_unverified(): void
    {
        // Switching the fallback to SES: until SES is verified and chosen, mail
        // keeps going out through the verified SMTP default.
        $this->markVerified($this->smtpConfig(null, ['is_default' => true]));
        $this->sesConfig(null);

        $this->assertSame('smtp.fallback.test', $this->service->resolveFor($this->whiteLabel->id)->smtp_host);
    }

    #[Test]
    public function the_fallback_sends_with_its_chosen_default(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->markVerified($this->sesConfig(null, ['is_default' => true]));

        $this->assertSame('AKIAFALLBACK', $this->service->resolveFor($this->whiteLabel->id)->aws_access_key);
    }

    #[Test]
    public function an_unverified_fallback_default_gives_way_to_another_verified_fallback_provider(): void
    {
        // The fallback is the last resort: rather than send nothing, it uses any
        // of its providers that is verified.
        $this->smtpConfig(null, ['is_default' => true]);
        $this->markVerified($this->sesConfig(null));

        $this->assertSame('AKIAFALLBACK', $this->service->resolveFor($this->whiteLabel->id)->aws_access_key);
    }

    #[Test]
    public function nothing_configured_resolves_to_nothing(): void
    {
        $this->assertNull($this->service->resolveFor($this->whiteLabel->id));
    }

    #[Test]
    public function whether_a_partner_sends_under_its_own_settings(): void
    {
        $this->markVerified($this->smtpConfig(null));
        $this->assertFalse($this->service->usesOwnConfiguration($this->whiteLabel->id));

        $this->markVerified($this->smtpConfig($this->whiteLabel, ['is_default' => true]));
        $this->assertTrue($this->service->usesOwnConfiguration($this->whiteLabel->id));
    }
}
