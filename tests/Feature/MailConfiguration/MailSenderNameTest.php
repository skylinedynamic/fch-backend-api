<?php

namespace Tests\Feature\MailConfiguration;

use App\Mail\Traits\MailConfig;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Support\Mail\MailSenderName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The name mail is sent under: the partner's own - whether it goes out
 * through its own settings or the platform fallback - never another
 * partner's, and never blank.
 */
class MailSenderNameTest extends TestCase
{
    use RefreshDatabase;
    use MailConfigurationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPartners();
    }

    private function siteName(Partner $partner, string $value): void
    {
        AppConfiguration::create([
            'partner_id' => $partner->id,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'site_name',
            'value' => $value,
        ]);
    }

    #[Test]
    public function the_partners_branded_site_name_comes_first(): void
    {
        $this->siteName($this->whiteLabel, 'Acme Lending');

        $this->assertSame('Acme Lending', MailSenderName::for($this->whiteLabel->id));
    }

    #[Test]
    public function without_one_it_is_the_partners_company_name(): void
    {
        $this->assertSame('Acme Capital', MailSenderName::for($this->whiteLabel->id));
    }

    #[Test]
    public function a_blank_or_null_site_name_counts_as_none(): void
    {
        $this->siteName($this->whiteLabel, 'null');

        $this->assertSame('Acme Capital', MailSenderName::for($this->whiteLabel->id));
    }

    #[Test]
    public function another_partners_name_is_never_used(): void
    {
        // The old lookup ignored the partner when it had no row of its own.
        $this->siteName($this->host, 'Four Corner Funding');

        $this->assertSame('Acme Capital', MailSenderName::for($this->whiteLabel->id));
    }

    #[Test]
    public function mail_with_no_partner_goes_out_under_the_hosts_name(): void
    {
        $this->siteName($this->whiteLabel, 'Acme Lending');
        $this->siteName($this->host, 'FCF Platform');

        $this->assertSame('FCF Platform', MailSenderName::for(null));
    }

    #[Test]
    public function with_no_name_anywhere_it_is_the_app_name(): void
    {
        config(['app.name' => 'FCH']);
        $this->whiteLabel->update(['company_name' => '']);

        $this->assertSame('FCH', MailSenderName::for($this->whiteLabel->id));
    }

    #[Test]
    public function the_mailer_sends_under_the_partners_name_even_through_the_fallback(): void
    {
        // The fallback's own settings, sending a white-label partner's mail.
        $fallback = $this->markVerified($this->smtpConfig(null))->toMailSettings();

        $mailable = new class extends Mailable {
            use MailConfig;
        };

        $apply = new \ReflectionMethod($mailable, 'apply');
        $apply->setAccessible(true);
        $apply->invoke($mailable, $fallback, $this->whiteLabel->id);

        $this->assertSame('Acme Capital', config('mail.from.name'));
        $this->assertSame('mail@fallback.test', $mailable->from[0]['address']);
        $this->assertSame('Acme Capital', $mailable->from[0]['name']);
    }
}
