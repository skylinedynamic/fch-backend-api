<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\Global\Website\CMS\BlogController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\SeoController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\SiteSettingsController;
use App\Http\Controllers\Api\V1\Global\Website\Lead\LeadController;
use App\Http\Controllers\Api\V1\Global\Website\PreQualification\PreQualificationController;
use App\Http\Controllers\DomainResolverController;
use App\Models\Common\AppConfiguration;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /**
     * reCAPTCHA set up in Site Settings: the secret and the site key, as Site
     * Settings saves them (module website, the host's row). The check runs only
     * with both - the website makes a token only when it has the site key.
     */
    private function siteSettingsSecret(string $secret): void
    {
        AppConfiguration::set('recaptcha_secret_key', $secret, AppConfiguration::MODULE_WEBSITE, $this->host->id);
        AppConfiguration::set('recaptcha_site_key', 'site-key-public', AppConfiguration::MODULE_WEBSITE, $this->host->id);
    }

    private ?array $googleAnswer = null;

    /**
     * Google's siteverify answer for a contact-form token. One fake reads the
     * current answer - a second Http::fake for the same URL would never be hit.
     */
    private function googleSays(array $answer): void
    {
        if ($this->googleAnswer === null) {
            Http::fake(['www.google.com/recaptcha/api/siteverify' => fn () => Http::response($this->googleAnswer)]);
        }

        $this->googleAnswer = array_merge(['success' => true, 'score' => 0.9, 'action' => 'contact_form'], $answer);
    }

    private function contactForm(array $overrides = []): array
    {
        return array_merge([
            'contact_person' => 'Visitor Vee',
            'email' => 'vee-' . uniqid() . '@example.test',
            'recaptcha_token' => 'token-from-the-browser',
        ], $overrides);
    }

    /**
     * Defect (findings D5/O29): the contact form sends a reCAPTCHA v3 token
     * that was never checked, and the secret saved in Site Settings was read
     * from the wrong place (module Partner, partner 1) so it was never used.
     * The token is now verified with the Site Settings secret.
     */
    #[Test]
    public function a_contact_form_lead_is_verified_with_the_site_settings_secret(): void
    {
        $this->siteSettingsSecret('secret-from-site-settings');
        $this->googleSays([]);

        $this->postJson('/api/v1/website/leads', $this->contactForm())->assertOk();

        $this->assertSame(1, Lead::count());
        Http::assertSent(fn ($request) => $request['secret'] === 'secret-from-site-settings'
            && $request['response'] === 'token-from-the-browser');
    }

    #[Test]
    public function a_contact_form_lead_without_a_good_token_is_refused(): void
    {
        $this->siteSettingsSecret('secret-from-site-settings');

        $this->googleSays([]);
        $this->postJson('/api/v1/website/leads', $this->contactForm(['recaptcha_token' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['recaptcha_token']);

        foreach ([['success' => false], ['score' => 0.1], ['action' => 'some_other_form']] as $answer) {
            $this->googleSays($answer);
            $this->postJson('/api/v1/website/leads', $this->contactForm())
                ->assertStatus(422)->assertJsonValidationErrors(['recaptcha_token']);
        }

        $this->assertSame(0, Lead::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    /**
     * Site Settings is the only source (findings O57): the keys used to fall
     * back to RECAPTCHA_SECRET_KEY in .env, a second, stale copy - and the one
     * committed to .env.example. An env secret is now never read: with nothing
     * in Site Settings the check is simply off.
     */
    #[Test]
    public function an_env_secret_is_not_used(): void
    {
        putenv('RECAPTCHA_SECRET_KEY=secret-from-env');
        config(['captcha.secret' => 'secret-from-env']);
        Http::fake();

        try {
            $this->postJson('/api/v1/website/leads', $this->contactForm(['recaptcha_token' => null]))->assertOk();
        } finally {
            putenv('RECAPTCHA_SECRET_KEY');
        }

        Http::assertNothingSent();
        $this->assertFalse(file_exists(config_path('captcha.php')), 'config/captcha.php is gone - nothing reads it');
    }

    /**
     * Half set up - a secret with no site key, or a site key with no secret -
     * is off too. The website cannot make a token without the site key, and
     * the API cannot check one without the secret, so demanding one would turn
     * every lead away ("Please complete the reCAPTCHA verification.").
     */
    #[Test]
    public function with_only_one_of_the_two_keys_saved_leads_are_taken_without_a_check(): void
    {
        Http::fake();

        AppConfiguration::set('recaptcha_secret_key', 'secret-only', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        $this->postJson('/api/v1/website/leads', $this->contactForm(['recaptcha_token' => null]))->assertOk();

        AppConfiguration::set('recaptcha_secret_key', '', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        AppConfiguration::set('recaptcha_site_key', 'site-key-only', AppConfiguration::MODULE_WEBSITE, $this->host->id);
        $this->postJson('/api/v1/website/leads', $this->contactForm(['recaptcha_token' => null]))->assertOk();

        $this->assertSame(2, Lead::count());
        Http::assertNothingSent();
    }

    /** With no secret anywhere, the check is skipped rather than blocking every lead. */
    #[Test]
    public function with_no_secret_configured_leads_are_taken_without_a_check(): void
    {
        Http::fake();

        $this->postJson('/api/v1/website/leads', $this->contactForm(['recaptcha_token' => null]))->assertOk();

        $this->assertSame(1, Lead::count());
        Http::assertNothingSent();
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
    public function a_website_lead_needs_a_real_us_phone_number(): void
    {
        $this->postJson('/api/v1/website/leads', $this->contactForm(['phone_number' => '(124) 982-7457']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number' => __('embed_form.phone_invalid')]);
    }

    #[Test]
    public function a_website_lead_is_filed_with_the_host_and_its_phone_normalised(): void
    {
        $this->postJson('/api/v1/website/leads', [
            'contact_person' => 'Visitor Vee',
            'email' => 'vee@example.test',
            'phone_number' => '5552345678',
            'company_name' => 'Vee Co',
        ])->assertOk();

        $lead = Lead::where('email', 'vee@example.test')->firstOrFail();
        $this->assertSame($this->host->id, $lead->partner_id);
        $this->assertSame('(555) 234-5678', $lead->phone_number);
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

    /**
     * Defect (findings D7): any domain starting with api., partner. or app. got
     * a go-ahead by its name alone, so app.<anything> could have a certificate
     * requested - issuance abuse, and a way to burn the Let's Encrypt rate
     * limit the real partners need. Such a domain now has to be in the
     * database like any other partner domain.
     */
    #[Test]
    public function an_api_app_or_partner_prefix_alone_gets_no_certificate(): void
    {
        $this->makeWhiteLabelPartner(['partner_domain' => 'app.acme.test', 'active_custom_url' => true]);
        $this->makeWhiteLabelPartner(['partner_subdomain' => 'partner.beta.test']);
        $this->makeWhiteLabelPartner(['borrower_domain' => 'app.gamma.test', 'active_custom_url' => false]);
        $check = fn (string $domain) => $this->get('/api/caddy/domain-check?domain=' . urlencode($domain));

        $check('app.random-site.test')->assertForbidden();
        $check('api.attacker.test')->assertForbidden();
        $check('partner.nobody.test')->assertForbidden();

        $check('app.acme.test')->assertOk();
        $check('partner.beta.test')->assertOk();
        $check('app.gamma.test')->assertForbidden();
    }

    /**
     * The platform's own domains - the reserved list in config/domains.php -
     * always get a certificate go-ahead (findings D7). The check used to answer
     * 403 for exactly these, so they depended entirely on being configured in
     * Caddy by hand.
     */
    #[Test]
    public function the_reserved_system_domains_always_get_a_certificate_go_ahead(): void
    {
        config(['domains.reserved' => [
            'base_reserved_website' => 'fch.test',
            'base_reserved_api_portal' => 'api.fch.test',
            'base_reserved_partner_portal' => 'partner.fch.test',
            'base_reserved_borrower_portal' => 'app.fch.test',
        ]]);

        foreach (['fch.test', 'api.fch.test', 'partner.fch.test', 'app.fch.test', 'APP.fch.test.'] as $domain) {
            $this->get('/api/caddy/domain-check?domain=' . urlencode($domain))->assertOk();
        }
    }
}
