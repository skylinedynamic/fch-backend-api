<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\Global\Website\CMS\BlogController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\SeoController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\SiteSettingsController;
use App\Http\Controllers\Api\V1\Global\Website\Lead\LeadController;
use App\Http\Controllers\Api\V1\Global\Website\PreQualification\PreQualificationController;
use App\Http\Controllers\DomainResolverController;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The unauthenticated surface: the marketing site's API (/api/v1/website/*)
 * and the infrastructure hooks the edge proxy calls (/api/resolve-custom-domain,
 * /api/caddy/domain-check). See findings D5, D7, D8 for what is recorded
 * about their abuse resistance.
 */
#[CoversClass(LeadController::class)]
#[CoversClass(PreQualificationController::class)]
#[CoversClass(BlogController::class)]
#[CoversClass(SeoController::class)]
#[CoversClass(SiteSettingsController::class)]
#[CoversClass(DomainResolverController::class)]
class PublicWebsiteApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
    }

    private function blog(array $attributes = []): int
    {
        return DB::table('blogs')->insertGetId(array_merge([
            'title' => 'How to build credit',
            'slug' => 'build-credit-' . uniqid(),
            'content' => '<p>Start here.</p>',
            'partner_id' => $this->host->id,
            'is_published' => true,
            'published_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function a_website_lead_is_filed_with_the_host_and_its_phone_normalised(): void
    {
        $this->postJson('/api/v1/website/leads', [
            'contact_person' => 'Visitor Vee',
            'email' => 'vee@example.test',
            'phone_number' => '5551234567',
            'company_name' => 'Vee Co',
        ])->assertOk();

        $lead = Lead::where('email', 'vee@example.test')->firstOrFail();
        $this->assertSame($this->host->id, $lead->partner_id);
        $this->assertSame('(555) 123-4567', $lead->phone_number);
    }

    #[Test]
    public function a_website_lead_needs_a_name_and_valid_email_and_phone(): void
    {
        $this->postJson('/api/v1/website/leads', ['email' => 'nope', 'phone_number' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_person', 'email', 'phone_number']);
    }

    #[Test]
    public function a_website_pre_qualification_needs_the_business_and_funding_basics(): void
    {
        $this->postJson('/api/v1/website/pre-qualification', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name', 'last_name', 'email', 'business_name', 'monthly_revenue',
                'funding_requested_amount', 'existing_business_debt',
            ]);
    }

    #[Test]
    public function only_published_blog_posts_are_public(): void
    {
        $published = $this->blog(['slug' => 'live-post']);
        $this->blog(['slug' => 'draft-post', 'is_published' => false]);
        $this->blog(['slug' => 'future-post', 'published_at' => now()->addWeek()]);

        // A Laravel paginator: the posts sit under data.data.
        $slugs = collect($this->getJson('/api/v1/website/blogs')->assertOk()->json('data.data'))->pluck('slug');
        $this->assertSame(['live-post'], $slugs->all());

        $this->getJson('/api/v1/website/blogs/live-post')->assertOk()->assertJsonPath('data.id', $published);
        $this->getJson('/api/v1/website/blogs/draft-post')->assertNotFound();
        $this->getJson('/api/v1/website/blogs/future-post')->assertNotFound();
    }

    #[Test]
    public function the_blog_sidebars_seo_and_settings_load(): void
    {
        $this->getJson('/api/v1/website/blogs/categories')->assertOk();
        $this->getJson('/api/v1/website/blogs/tags')->assertOk();
        $this->getJson('/api/v1/website/blogs/latest')->assertOk();
        $this->getJson('/api/v1/website/seo?path=/')->assertOk();
        $this->getJson('/api/v1/website/settings')->assertOk();
        $this->getJson('/api/v1/website/ping')->assertOk()->assertJsonPath('ping', 'pong');
    }

    /**
     * Defect: the public settings listed recaptcha_secret_key among the keys
     * it returns, so anyone could read the host's reCAPTCHA secret - the key
     * that is meant to stay server-side - from an unauthenticated endpoint.
     */
    #[Test]
    public function the_public_settings_never_include_the_recaptcha_secret(): void
    {
        $hostId = Partner::where('is_host', true)->value('id');
        foreach (['site_name' => 'Four Corner', 'recaptcha_site_key' => 'site-key', 'recaptcha_secret_key' => 'top-secret'] as $key => $value) {
            \App\Models\Common\AppConfiguration::set($key, $value, \App\Models\Common\AppConfiguration::MODULE_WEBSITE, $hostId);
        }

        $this->getJson('/api/v1/website/settings')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'Four Corner')
            ->assertJsonPath('data.recaptcha_site_key', 'site-key')
            ->assertJsonMissingPath('data.recaptcha_secret_key')
            ->assertDontSee('top-secret');
    }

    #[Test]
    public function the_domain_resolver_needs_the_internal_key(): void
    {
        config(['domains.internal_key' => 'test-internal-key']);
        $this->makeWhiteLabelPartner(['partner_subdomain' => 'acme.fch.test']);

        $this->get('/api/resolve-custom-domain', ['X-Domain' => 'acme.fch.test'])->assertUnauthorized();
        $this->get('/api/resolve-custom-domain', ['X-Domain' => 'acme.fch.test', 'X-Internal-Key' => 'wrong'])->assertUnauthorized();
    }

    /**
     * Defect (D8): the key had a default committed to the repo (config and
     * .env.example), so any environment that never set INTERNAL_RESOLVER_KEY
     * accepted that public value - and with no key at all, a request without
     * the header matched the missing key. A missing key now fails closed.
     */
    #[Test]
    public function without_a_configured_key_the_domain_resolver_refuses_everyone(): void
    {
        $this->makeWhiteLabelPartner(['partner_subdomain' => 'acme.fch.test']);

        foreach ([null, ''] as $unset) {
            config(['domains.internal_key' => $unset]);

            $this->get('/api/resolve-custom-domain', ['X-Domain' => 'acme.fch.test'])->assertUnauthorized();
            $this->get('/api/resolve-custom-domain', ['X-Domain' => 'acme.fch.test', 'X-Internal-Key' => ''])->assertUnauthorized();
            $this->get('/api/resolve-custom-domain', ['X-Domain' => 'acme.fch.test', 'X-Internal-Key' => 'fourcornerfunding2026'])->assertUnauthorized();
        }

        $this->assertStringNotContainsString('fourcornerfunding2026', file_get_contents(config_path('domains.php')));
        $this->assertStringNotContainsString('fourcornerfunding2026', file_get_contents(base_path('.env.example')));
    }

    #[Test]
    public function the_domain_resolver_maps_subdomains_and_activated_custom_domains_to_their_partner(): void
    {
        config(['domains.internal_key' => 'test-internal-key']);
        $partner = $this->makeWhiteLabelPartner([
            'partner_subdomain' => 'acme.fch.test',
            'borrower_domain' => 'clients.acme.test',
            'partner_domain' => 'portal.acme.test',
            'active_custom_url' => true,
        ]);
        $pending = $this->makeWhiteLabelPartner(['partner_domain' => 'later.test', 'active_custom_url' => false]);
        $resolve = fn (string $domain) => $this->get('/api/resolve-custom-domain', [
            'X-Domain' => $domain,
            'X-Internal-Key' => 'test-internal-key',
        ]);

        $resolve('acme.fch.test')->assertOk()->assertHeader('X-Tenant-Type', 'partner')->assertHeader('X-Partner-Id', (string) $partner->id);
        $resolve('www.portal.acme.test')->assertOk()->assertHeader('X-Tenant-Type', 'partner');
        $resolve('clients.acme.test')->assertOk()->assertHeader('X-Tenant-Type', 'borrower');
        $resolve('later.test')->assertNotFound();
        $resolve('nobody.test')->assertNotFound();
        $this->assertNotNull($pending);
    }

    #[Test]
    public function caddy_gets_a_certificate_go_ahead_only_for_known_domains(): void
    {
        $this->makeWhiteLabelPartner(['partner_subdomain' => 'acme.fch.test']);
        $this->makeWhiteLabelPartner(['partner_domain' => 'portal.acme.test', 'active_custom_url' => true]);
        $this->makeWhiteLabelPartner(['partner_domain' => 'later.test', 'active_custom_url' => false]);
        $check = fn (string $domain) => $this->get('/api/caddy/domain-check?domain=' . urlencode($domain));

        $check('acme.fch.test')->assertOk();
        $check('portal.acme.test')->assertOk();
        $check('later.test')->assertForbidden();
        $check('random.example.test')->assertForbidden();
        $this->get('/api/caddy/domain-check')->assertStatus(400);
    }
}
