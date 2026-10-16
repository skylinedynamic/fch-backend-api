<?php

namespace Tests\Feature\Branding;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The <head> tags a link preview is built from - title, description, Open Graph
 * and Twitter tags, and the favicon - for whichever portal domain is asking.
 *
 * Link previews (WhatsApp, Facebook, LinkedIn, Slack) never run JavaScript, so
 * the portals cannot set these themselves: Nginx includes this fragment into
 * index.html (SSI) as it serves it.
 */
class PortalMetaTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = 'https://api.example.test';

    private static int $versionSeed = 700000;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        // Branding is memoised per process; give each test its own version band.
        Cache::forever('branding:version', self::$versionSeed += 1000);

        config(['filesystems.disks.public.url' => self::API_BASE . '/storage']);
        Storage::forgetDisk('public');

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host',
            'email' => 'host-' . uniqid() . '@example.test',
            'borrower_domain' => 'app.fch.test',
            'partner_subdomain' => 'partner.fch.test',
        ]);
        $this->brand($this->host, [
            'site_name' => 'Four Corner Funding',
            'site_slogan' => 'Funding for growing businesses',
            'logo_document' => 'site-settings/host-doc.png',
            'favicon' => 'site-settings/host-fav.png',
        ]);
    }

    private function partner(string $type, array $attributes = []): Partner
    {
        return Partner::create(array_merge([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => 'Acme Capital',
            'full_name' => 'Partner Contact',
            'email' => 'partner-' . uniqid() . '@example.test',
        ], $attributes));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function brand(Partner $partner, array $values): void
    {
        foreach ($values as $key => $value) {
            AppConfiguration::create([
                'partner_id' => $partner->id,
                'module' => AppConfiguration::MODULE_BRANDING,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    private function meta(string $domain, string $portal = 'borrower'): TestResponse
    {
        return $this->get('/api/portal-meta?portal=' . $portal . '&d=' . urlencode($domain));
    }

    #[Test]
    public function a_white_label_borrower_portal_previews_as_that_partner(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, [
            'site_name' => 'Acme Capital',
            'site_slogan' => 'Capital for Main Street',
            'logo_document' => 'partners/acme/doc.png',
            'favicon' => 'partners/acme/fav.png',
        ]);

        $response = $this->meta('apply.acme.test');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('<title>Acme Capital | Borrower Portal</title>', false);
        $response->assertSee('<meta property="og:title" content="Acme Capital | Borrower Portal">', false);
        $response->assertSee('<meta property="og:site_name" content="Acme Capital">', false);
        $response->assertSee('<meta name="description" content="Capital for Main Street">', false);
        $response->assertSee('<meta property="og:description" content="Capital for Main Street">', false);
        $response->assertSee('<meta property="og:image" content="' . self::API_BASE . '/storage/partners/acme/doc.png">', false);
        $response->assertSee('<meta name="twitter:image" content="' . self::API_BASE . '/storage/partners/acme/doc.png">', false);
        $response->assertSee('<meta property="og:url" content="https://apply.acme.test/">', false);
        $response->assertSee('<link rel="icon" href="' . self::API_BASE . '/storage/partners/acme/fav.png">', false);
        $response->assertDontSee('Four Corner Funding', false);
        $response->assertDontSee('host-doc.png', false);
    }

    #[Test]
    public function the_partner_portal_is_titled_as_the_partner_dashboard(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['partner_subdomain' => 'acme.fch.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital', 'logo_document' => 'partners/acme/doc.png']);

        $response = $this->meta('acme.fch.test', 'partner');

        $response->assertOk();
        $response->assertSee('<title>Acme Capital | Partner Dashboard</title>', false);
        $response->assertSee('<meta property="og:title" content="Acme Capital | Partner Dashboard">', false);
    }

    #[Test]
    public function the_hosts_own_domain_previews_as_the_host(): void
    {
        $response = $this->meta('app.fch.test');

        $response->assertOk();
        $response->assertSee('<title>Four Corner Funding | Borrower Portal</title>', false);
        $response->assertSee('<meta property="og:image" content="' . self::API_BASE . '/storage/site-settings/host-doc.png">', false);
        $response->assertSee('<link rel="icon" href="' . self::API_BASE . '/storage/site-settings/host-fav.png">', false);
    }

    #[Test]
    public function a_partner_that_is_not_white_label_previews_as_the_host(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['borrower_domain' => 'apply.certified.test']);
        $this->brand($partner, ['site_name' => 'Certified Co', 'logo_document' => 'partners/certified/doc.png']);

        $response = $this->meta('apply.certified.test');

        $response->assertSee('<title>Four Corner Funding | Borrower Portal</title>', false);
        $response->assertSee('host-doc.png', false);
        $response->assertDontSee('Certified Co', false);
        $response->assertDontSee('certified/doc.png', false);
    }

    #[Test]
    public function an_unknown_domain_previews_as_the_host(): void
    {
        $response = $this->meta('nobody.example.test');

        $response->assertOk();
        $response->assertSee('<title>Four Corner Funding | Borrower Portal</title>', false);
    }

    #[Test]
    public function the_domain_can_come_from_the_header_nginx_sends(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital']);

        $response = $this->withHeader('X-Portal-Host', 'apply.acme.test')->get('/api/portal-meta?portal=borrower');

        $response->assertSee('<title>Acme Capital | Borrower Portal</title>', false);
        $response->assertSee('<meta property="og:url" content="https://apply.acme.test/">', false);
    }

    #[Test]
    public function without_a_slogan_the_description_names_the_portal(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital']);
        AppConfiguration::where('partner_id', $this->host->id)->where('key', 'site_slogan')->delete();
        Cache::forever('branding:version', self::$versionSeed += 1);

        $this->meta('apply.acme.test')
            ->assertSee('<meta name="description" content="Sign in to the Acme Capital borrower portal to apply for funding and track your applications.">', false);
        $this->meta('apply.acme.test', 'partner')
            ->assertSee('<meta name="description" content="Sign in to the Acme Capital partner dashboard to manage your borrowers and their funding.">', false);
    }

    #[Test]
    public function an_svg_logo_is_skipped_for_one_link_previews_can_show(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, [
            'site_name' => 'Acme Capital',
            'logo_document' => 'partners/acme/doc.svg',
            'logo_sidebar' => 'partners/acme/sidebar.png',
        ]);

        $response = $this->meta('apply.acme.test');

        $response->assertSee('<meta property="og:image" content="' . self::API_BASE . '/storage/partners/acme/sidebar.png">', false);
        $response->assertDontSee('doc.svg', false);
    }

    #[Test]
    public function a_partner_with_no_logo_gets_its_name_as_a_png_placeholder(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital']);

        $response = $this->meta('apply.acme.test');

        $response->assertSee('<meta property="og:image" content="https://placehold.co/230x70/png?text=Acme+Capital">', false);
        $response->assertDontSee('host-doc.png', false);
    }

    #[Test]
    public function a_partner_with_no_favicon_shows_the_one_its_portal_tab_shows(): void
    {
        // Branding deliberately lends the host's favicon to a tenant without one.
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital', 'logo_document' => 'partners/acme/doc.png']);

        $this->meta('apply.acme.test')
            ->assertSee('<link rel="icon" href="' . self::API_BASE . '/storage/site-settings/host-fav.png">', false);
    }

    #[Test]
    public function with_no_favicon_anywhere_the_portals_packaged_one_stays(): void
    {
        // index.html declares the packaged icon itself; only a real upload
        // follows it, and the later icon is the one the tab shows.
        AppConfiguration::where('partner_id', $this->host->id)->where('key', 'favicon')->delete();
        Cache::forever('branding:version', self::$versionSeed += 1);

        $this->meta('app.fch.test')
            ->assertOk()
            ->assertDontSee('rel="icon"', false);
    }

    #[Test]
    public function branding_text_is_escaped(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['borrower_domain' => 'apply.acme.test']);
        $this->brand($partner, ['site_name' => 'Acme "Capital" <script>alert(1)</script>']);

        $response = $this->meta('apply.acme.test');

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    #[Test]
    public function a_domain_that_is_not_a_hostname_is_not_echoed_back(): void
    {
        $response = $this->meta('evil.test"><script>x</script>');

        $response->assertOk();
        $response->assertDontSee('<script>x</script>', false);
        $response->assertDontSee('og:url', false);
    }

    // -- Custom domains: Caddy sends every one to the same Nginx server, which
    //    cannot know which portal it is serving, so the API tells from the domain.

    #[Test]
    public function a_partners_borrower_domain_is_its_borrower_portal(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'borrower_domain' => 'apply.acme.test',
            'partner_domain' => 'admin.acme.test',
        ]);
        $this->brand($partner, ['site_name' => 'Acme Capital']);

        $this->withHeader('X-Portal-Host', 'apply.acme.test')->get('/api/portal-meta')
            ->assertSee('<title>Acme Capital | Borrower Portal</title>', false);
    }

    #[Test]
    public function a_partners_own_domain_is_its_partner_dashboard(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, [
            'borrower_domain' => 'apply.acme.test',
            'partner_domain' => 'admin.acme.test',
        ]);
        $this->brand($partner, ['site_name' => 'Acme Capital']);

        $this->withHeader('X-Portal-Host', 'admin.acme.test')->get('/api/portal-meta')
            ->assertSee('<title>Acme Capital | Partner Dashboard</title>', false);
    }

    #[Test]
    public function a_partners_subdomain_is_its_partner_dashboard(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['partner_subdomain' => 'acme.fch.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital']);

        $this->withHeader('X-Portal-Host', 'www.acme.fch.test')->get('/api/portal-meta')
            ->assertSee('<title>Acme Capital | Partner Dashboard</title>', false);
    }

    #[Test]
    public function the_portal_named_in_the_request_wins_over_the_domain(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['partner_domain' => 'admin.acme.test']);
        $this->brand($partner, ['site_name' => 'Acme Capital']);

        $this->meta('admin.acme.test', 'borrower')
            ->assertSee('<title>Acme Capital | Borrower Portal</title>', false);
    }

    #[Test]
    public function an_unknown_portal_is_the_borrower_portal(): void
    {
        $this->meta('app.fch.test', 'nonsense')
            ->assertSee('<title>Four Corner Funding | Borrower Portal</title>', false);
    }

    #[Test]
    public function the_fragment_is_cacheable_for_a_few_minutes(): void
    {
        $this->meta('app.fch.test')->assertHeader('Cache-Control', 'max-age=300, public');
    }
}
