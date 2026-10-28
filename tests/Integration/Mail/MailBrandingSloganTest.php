<?php

namespace Tests\Integration\Mail;

use App\Mail\Traits\MailableBranding;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The tagline under the logo in every email.
 *
 * Defect: R2 BUG-073 - a partner with no slogan of its own got the host's
 * "Where Strategy Meets Funding." under the partner's own name and logo. A
 * partner's email shows only the partner's own slogan, or none.
 */
#[CoversTrait(MailableBranding::class)]
class MailBrandingSloganTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->slogan($this->host, 'Where Strategy Meets Funding.');
    }

    private function slogan(Partner $partner, string $value): void
    {
        AppConfiguration::create([
            'partner_id' => $partner->id,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'site_slogan',
            'value' => $value,
        ]);
    }

    private function brandingFor(Partner $partner): array
    {
        $mail = new class {
            use MailableBranding;

            public function for(int $partnerId): array
            {
                return $this->getBrandingData($partnerId);
            }
        };

        return $mail->for($partner->id);
    }

    #[Test]
    public function the_hosts_email_carries_the_hosts_slogan(): void
    {
        $this->assertSame('Where Strategy Meets Funding.', $this->brandingFor($this->host)['siteSlogan']);
    }

    #[Test]
    public function a_partner_without_a_slogan_shows_none_never_the_hosts(): void
    {
        $this->assertNull($this->brandingFor($this->makeWhiteLabelPartner())['siteSlogan']);
        $this->assertNull($this->brandingFor($this->makeCertifiedPartner())['siteSlogan']);
    }

    #[Test]
    public function a_partner_with_its_own_slogan_shows_it(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $this->slogan($partner, 'Capital for Main Street');

        $this->assertSame('Capital for Main Street', $this->brandingFor($partner)['siteSlogan']);
    }
}
