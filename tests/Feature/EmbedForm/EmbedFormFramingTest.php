<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Controllers\Web\EmbedFormPageController;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-092: Embedded Form > Allowed Domains did not stop the form showing in an
 * iframe on any other site - the page sent no framing rule, and the submit's
 * Origin check sees the form's own host, not the page around the iframe. The
 * page now tells the browser which sites may frame it (CSP frame-ancestors):
 * the listed domains, plus the partner's own admin panel so the embed preview
 * keeps working. An empty list stays unrestricted.
 */
#[CoversClass(EmbedFormPageController::class)]
class EmbedFormFramingTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'https://admin.skylinedynamic.dev';

    protected function setUp(): void
    {
        parent::setUp();

        // As on stage: the admin panel, the public website and the API.
        config([
            'app.frontend_partner_url' => self::PORTAL,
            'app.base_website' => 'skylinedynamic.dev',
            'app.url' => 'https://api.skylinedynamic.dev',
        ]);
    }

    private function page(?array $allowedDomains, array $partner = []): TestResponse
    {
        $partner = Partner::create($partner + [
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

    private function frameAncestors(TestResponse $response): ?string
    {
        $csp = $response->headers->get('Content-Security-Policy');
        if (!$csp || !preg_match('/frame-ancestors ([^;]+)/', $csp, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    #[Test]
    public function only_the_listed_domains_and_the_admin_panel_may_show_the_form_in_an_iframe(): void
    {
        $sources = $this->frameAncestors($this->page(['https://skylinedynamic.dev']));

        $this->assertSame(
            'https://skylinedynamic.dev https://www.skylinedynamic.dev ' . self::PORTAL,
            $sources
        );
        $this->assertStringNotContainsString("'self'", $sources);
        $this->assertStringNotContainsString('example.com', $sources);
    }

    #[Test]
    public function the_admin_panel_preview_is_allowed_whatever_domains_are_listed(): void
    {
        // Stage: Allowed Domains held only the website, and the preview inside
        // admin.skylinedynamic.dev showed "refused to connect".
        $sources = explode(' ', $this->frameAncestors($this->page(['partner-site.com'])));

        $this->assertContains(self::PORTAL, $sources);
        $this->assertContains('https://partner-site.com', $sources);
        $this->assertNotContains('https://www.admin.skylinedynamic.dev', $sources);
    }

    #[Test]
    public function a_portal_address_without_a_scheme_keeps_its_port(): void
    {
        // Local .env: BASE_PARTNER_PORTAL = "localhost:5174". A source with no
        // scheme takes the form page's own, so http locally and https on stage.
        config(['app.frontend_partner_url' => 'localhost:5174']);

        $sources = explode(' ', $this->frameAncestors($this->page(['partner-site.com'])));

        $this->assertContains('localhost:5174', $sources);
    }

    #[Test]
    public function another_partners_form_may_be_framed_by_its_own_portal_domains(): void
    {
        // A partner other than the host opens the admin panel on its own
        // domain or subdomain (partners table), not on BASE_PARTNER_PORTAL.
        $sources = explode(' ', $this->frameAncestors($this->page(['partner-site.com'], [
            'is_host' => false,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'partner_domain' => 'portal.exodias.com',
            'partner_subdomain' => 'exodias.skylinedynamic.dev',
        ])));

        $this->assertContains('portal.exodias.com', $sources);
        $this->assertContains('exodias.skylinedynamic.dev', $sources);
        $this->assertNotContains(self::PORTAL, $sources);
    }

    #[Test]
    public function a_partner_with_only_a_subdomain_is_framed_by_that_subdomain(): void
    {
        $sources = explode(' ', $this->frameAncestors($this->page(['partner-site.com'], [
            'is_host' => false,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'partner_subdomain' => 'https://bluerdige.skylinedynamic.dev/',
        ])));

        $this->assertContains('https://bluerdige.skylinedynamic.dev', $sources);
        $this->assertNotContains(self::PORTAL, $sources);
    }

    #[Test]
    public function the_host_partners_form_is_framed_only_by_base_partner_portal(): void
    {
        // The host's own domain columns are not its admin panel.
        $sources = explode(' ', $this->frameAncestors($this->page(['partner-site.com'], [
            'partner_subdomain' => 'host.skylinedynamic.dev',
        ])));

        $this->assertSame(['https://partner-site.com', 'https://www.partner-site.com', self::PORTAL], $sources);
    }

    #[Test]
    public function every_listed_domain_is_allowed_however_it_was_typed(): void
    {
        $sources = $this->frameAncestors($this->page(['https://www.partner-one.com/apply', 'http://partner-two.com']));

        // R2 BUG-035: a domain typed with http:// is allowed over https as well.
        $this->assertSame(
            'https://partner-one.com https://www.partner-one.com https://partner-two.com https://www.partner-two.com http://partner-two.com http://www.partner-two.com ' . self::PORTAL,
            $sources
        );
    }

    #[Test]
    public function a_stored_bare_host_is_allowed_over_https_only(): void
    {
        $this->assertSame(
            'https://x.com https://www.x.com ' . self::PORTAL,
            $this->frameAncestors($this->page(['x.com']))
        );
    }

    #[Test]
    public function with_no_list_the_form_may_be_framed_anywhere(): void
    {
        $this->assertNull($this->frameAncestors($this->page([])));
        $this->assertNull($this->frameAncestors($this->page(null)));
    }
}
