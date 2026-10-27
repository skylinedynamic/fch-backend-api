<?php

namespace Tests\Feature\Branding;

use App\Http\Controllers\Api\V1\Global\Branding\BrandingController;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-118: the login page of a partner's brand-new domain must carry a company
 * name from its first load - the host's for a Certified partner, the
 * partner's own for a white-label one.
 */
#[CoversClass(BrandingController::class)]
class NewPartnerDomainBrandingTest extends TestCase
{
    use RefreshDatabase;

    private static int $versionSeed = 900000;

    protected function setUp(): void
    {
        parent::setUp();

        // Branding is memoised per process; give each test its own version band.
        Cache::forever('branding:version', self::$versionSeed += 1000);

        $host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host',
            'email' => 'host@example.test',
        ]);
        AppConfiguration::create([
            'partner_id' => $host->id,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'site_name',
            'value' => 'Four Corner Funding',
        ]);
    }

    private function partner(string $type, array $attributes = []): Partner
    {
        return Partner::create(array_merge([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => 'QA-TEST Cert Two Co',
            'full_name' => 'Partner Contact',
            'email' => 'partner-' . uniqid() . '@example.test',
        ], $attributes));
    }

    private function siteName(string $domain): ?string
    {
        return $this->withHeader('X-Portal-Host', $domain)
            ->getJson('/api/v1/common/branding')
            ->assertOk()
            ->json('data.branding.site_name');
    }

    #[Test]
    public function a_new_certified_subdomain_shows_the_hosts_name(): void
    {
        $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['partner_subdomain' => 'qacerttwob.skylinedynamic.test']);

        $this->assertSame('Four Corner Funding', $this->siteName('qacerttwob.skylinedynamic.test'));
    }

    #[Test]
    public function a_subdomain_opened_before_it_was_saved_still_shows_the_hosts_name(): void
    {
        $this->assertSame('Four Corner Funding', $this->siteName('qacerttwob.skylinedynamic.test'));

        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED);
        $partner->update(['partner_subdomain' => 'qacerttwob.skylinedynamic.test']);

        $this->assertSame('Four Corner Funding', $this->siteName('qacerttwob.skylinedynamic.test'));
    }

    /**
     * Defect: the domain lookup is cached for an hour by partner id. When that
     * partner was deleted, the id found no partner and the branding came back
     * empty - "(c) 2026 . All rights reserved." - until the cache expired, even
     * after a new partner took the domain.
     */
    #[Test]
    public function a_subdomain_reused_after_its_partner_was_deleted_shows_the_hosts_name(): void
    {
        $old = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['company_name' => 'Old Co', 'partner_subdomain' => 'qacerttwob.skylinedynamic.test']);
        $this->siteName('qacerttwob.skylinedynamic.test');
        $old->delete();

        $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['partner_subdomain' => 'qacerttwob.skylinedynamic.test']);

        $this->assertSame('Four Corner Funding', $this->siteName('qacerttwob.skylinedynamic.test'));
    }

    /** A partner removed without model events (a query, not ->delete()) is not served from the cache either. */
    #[Test]
    public function a_cached_partner_that_no_longer_exists_is_looked_up_again(): void
    {
        $old = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['company_name' => 'Old Co', 'partner_subdomain' => 'qacerttwob.skylinedynamic.test']);
        $this->siteName('qacerttwob.skylinedynamic.test');

        Partner::whereKey($old->id)->forceDelete();

        $this->assertSame('Four Corner Funding', $this->siteName('qacerttwob.skylinedynamic.test'));
    }

    /** Defect: a domain moved to another partner kept the old one's branding for an hour. */
    #[Test]
    public function a_subdomain_moved_to_another_partner_shows_the_new_owners_brand(): void
    {
        $old = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['company_name' => 'Old Co', 'partner_subdomain' => 'qacerttwob.skylinedynamic.test']);
        AppConfiguration::create(['partner_id' => $old->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Old Co']);
        $this->assertSame('Old Co', $this->siteName('qacerttwob.skylinedynamic.test'));

        $old->update(['partner_subdomain' => null]);
        $this->partner(Partner::PARTNER_TYPE_CERTIFIED, ['partner_subdomain' => 'qacerttwob.skylinedynamic.test']);

        $this->assertSame('Four Corner Funding', $this->siteName('qacerttwob.skylinedynamic.test'));
    }

    /** Defect: a domain opened before its white-label partner had it showed the host's brand for an hour. */
    #[Test]
    public function a_white_label_subdomain_opened_before_it_was_saved_shows_its_own_name_once_saved(): void
    {
        $this->assertSame('Four Corner Funding', $this->siteName('acme.skylinedynamic.test'));

        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['company_name' => 'Acme Capital']);
        AppConfiguration::create(['partner_id' => $partner->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Acme Capital']);
        $partner->update(['partner_subdomain' => 'acme.skylinedynamic.test']);

        $this->assertSame('Acme Capital', $this->siteName('acme.skylinedynamic.test'));
    }

    #[Test]
    public function a_new_white_label_subdomain_shows_its_own_name(): void
    {
        $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL, ['company_name' => 'Acme Capital', 'partner_subdomain' => 'acme.skylinedynamic.test']);

        $this->assertNotEmpty($this->siteName('acme.skylinedynamic.test'));
    }
}
