<?php

namespace Tests\Feature\General;

use App\Http\Middleware\SecurityHeaders;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-013 (QA round 2): any site could show the API's own pages in an iframe -
 * the system dashboard's login, and behind it backup download, restore and
 * delete - so a page laid over them could get a click on them. Every response
 * now tells the browser only this site may frame it, and not to guess content
 * types. The embedded form is the one page made to be framed on partners'
 * sites; it keeps its own rule (BUG-092): its Allowed Domains, or anywhere.
 */
#[CoversClass(SecurityHeaders::class)]
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertNotFrameableElsewhere(TestResponse $response): void
    {
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    #[Test]
    public function the_system_dashboard_login_may_not_be_framed_by_another_site(): void
    {
        $this->assertNotFrameableElsewhere($this->get('/login')->assertOk());
    }

    #[Test]
    public function api_responses_carry_the_headers_too_even_errors(): void
    {
        $this->assertNotFrameableElsewhere($this->getJson('/api/v1/common/borrowers/1')->assertUnauthorized());
        $this->assertNotFrameableElsewhere($this->getJson('/api/v1/no-such-route')->assertNotFound());
    }

    private function embedForm(?array $allowedDomains): TestResponse
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co ' . uniqid(),
            'full_name' => 'Host Co',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
            'allowed_domains' => $allowedDomains,
        ]);

        return $this->get('/form/' . $config->public_key)->assertOk();
    }

    #[Test]
    public function the_embedded_form_keeps_its_allowed_domains_as_its_framing_rule(): void
    {
        $response = $this->embedForm(['https://partner-one.com']);

        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeader('Content-Security-Policy', 'frame-ancestors https://partner-one.com https://www.partner-one.com');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function the_embedded_form_with_no_allowed_domains_may_still_be_framed_anywhere(): void
    {
        $response = $this->embedForm([]);

        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
