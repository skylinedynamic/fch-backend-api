<?php

namespace Tests\Feature\Setting;

use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Rules\DomainNotClaimed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-021: a domain that looks like the host's brand - the brand on any
 * TLD, under any subdomain, or misspelt by a letter or two - can't be a
 * partner's custom domain, in every environment. The platform's own
 * subdomains (<label>.<base website>) are left to the claim checks.
 */
#[CoversClass(DomainNotClaimed::class)]
class TenantDomainLookalikeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // Stage: the platform runs on skylinedynamic.dev, the brand is still protected.
        config([
            'app.base_website' => 'skylinedynamic.dev',
            'domains.reserved' => [
                'base_reserved_website' => 'skylinedynamic.dev',
                'base_reserved_api_portal' => 'api-stage.skylinedynamic.dev',
                'base_reserved_partner_portal' => 'admin-stage.skylinedynamic.dev',
                'base_reserved_borrower_portal' => 'client-stage.skylinedynamic.dev',
            ],
            'domains.protected_brands' => ['fourcornerfunding'],
        ]);
        $this->hostAdmin = $this->makePartnerAdmin($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner(['partner_domain' => 'qarunbwl.skylinedynamic.dev']);
        $this->actingAsUser($this->hostAdmin);
    }

    private function save(array $overrides)
    {
        return $this->postJson("/api/v1/admin/partners/tenant-config/{$this->partner->id}", array_merge([
            'active_custom_url' => false,
            'partner_domain' => 'qarunbwl.skylinedynamic.dev',
            'borrower_domain' => null,
        ], $overrides));
    }

    /** @return iterable<string, array{string}> */
    public static function lookalikes(): iterable
    {
        yield 'QA: a letter left out' => ['fourcornerfundng.com'];
        yield 'QA: the live client portal' => ['app.fourcornerfunding.com'];
        yield 'QA: another TLD' => ['fourcornerfunding.co'];
        yield 'a zero for an o' => ['f0urcornerfunding.net'];
        yield 'the brand with a hyphen' => ['four-corner-funding.com'];
        yield 'the brand inside a longer name' => ['fourcornerfunding-login.com'];
        yield 'the brand as a subdomain elsewhere' => ['fourcornerfunding.evil.io'];
    }

    #[Test]
    #[DataProvider('lookalikes')]
    public function a_lookalike_of_the_brand_is_refused_as_the_borrower_domain(string $domain): void
    {
        $this->save(['borrower_domain' => $domain])
            ->assertStatus(422)
            ->assertJsonPath('errors.borrower_domain', __('partner.validation.domain_lookalike'));

        $this->assertNull($this->partner->fresh()->borrower_domain);
    }

    #[Test]
    public function a_lookalike_is_refused_as_the_partner_domain_too(): void
    {
        $this->save(['active_custom_url' => true, 'partner_domain' => 'portal.fourcornerfunding.co'])
            ->assertStatus(422)
            ->assertJsonPath('errors.partner_domain', __('partner.validation.domain_lookalike'));
    }

    /** @return iterable<string, array{string}> */
    public static function ownNames(): iterable
    {
        yield 'a different business' => ['acmefunding.com'];
        yield 'a word in common' => ['fourcorners.com'];
        yield 'a near but different name' => ['cornerstonefunding.com'];
        yield 'the platform subdomain' => ['qarunbwl-borrower.skylinedynamic.dev'];
    }

    #[Test]
    #[DataProvider('ownNames')]
    public function a_partners_own_name_still_saves(string $domain): void
    {
        $this->save(['borrower_domain' => $domain])->assertOk();
        $this->assertSame($domain, $this->partner->fresh()->borrower_domain);
    }

    #[Test]
    public function on_live_a_partner_subdomain_of_the_brand_site_is_the_platforms_own(): void
    {
        config([
            'app.base_website' => 'fourcornerfunding.com',
            'domains.reserved.base_reserved_website' => 'fourcornerfunding.com',
        ]);

        $this->save(['partner_domain' => 'qarunbwl.fourcornerfunding.com'])->assertOk();
    }
}
