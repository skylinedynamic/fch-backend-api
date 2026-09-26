<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Common\AppConfiguration;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rendered form page, logo included.
 *
 * EmbedFormLogoTest covers the ownership rule on its own; this renders
 * `/form/{key}` end to end, which is the only thing that proves the controller
 * still wires that rule to the `<img>` in the view. It was added after a green
 * suite hid a controller referencing a class that no longer existed: nothing
 * exercised the page, so nothing noticed.
 *
 * Each request is made against the partner-facing host, because that is where an
 * edge proxy publishes this page (see EmbedFormLocation) and the mismatch between
 * that host and the one serving `/storage` is what broke the logo in the first
 * place.
 */
class EmbedFormPageLogoTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = 'https://api.example.test';
    private const WEBSITE_URL = 'https://website.example.test';

    private static int $versionSeed = 500000;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        // See EmbedFormLogoTest: branding is memoised per process, so each test
        // needs a version band of its own.
        Cache::forever('branding:version', self::$versionSeed += 1000);

        config(['filesystems.disks.public.url' => self::API_BASE . '/storage']);
        Storage::forgetDisk('public');

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
    }

    #[Test]
    public function the_page_renders_the_logo_on_the_api_host_not_the_page_host(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL);
        $this->brand($partner, ['logo_document' => 'partners/2/wl-doc.png']);

        $response = $this->pageFor($partner);

        $response->assertOk();
        $response->assertSee(self::API_BASE . '/storage/partners/2/wl-doc.png', false);

        // The bug this pins: the logo built on whichever host served the page.
        $response->assertDontSee(self::WEBSITE_URL . '/storage/', false);
    }

    #[Test]
    public function a_certified_partner_page_renders_the_hosts_logo(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED);
        $this->brand($this->host, ['logo_document' => 'site-settings/host-doc.png']);
        $this->brand($partner, ['logo_document' => 'partners/2/certified-doc.png']);

        $response = $this->pageFor($partner);

        $response->assertOk();
        $response->assertSee(self::API_BASE . '/storage/site-settings/host-doc.png', false);
        $response->assertDontSee('certified-doc.png', false);
    }

    #[Test]
    public function a_partner_with_no_logo_falls_back_to_the_initial_avatar(): void
    {
        // The view drops the <img> entirely rather than render a broken one.
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL);

        $response = $this->pageFor($partner);

        $response->assertOk();
        $response->assertSee('brand-avatar', false);

        // No logo means no storage URL on the page at all. The class name itself
        // is no use as a signal - it is in the stylesheet either way.
        $response->assertDontSee(self::API_BASE . '/storage/', false);
    }

    private function pageFor(Partner $partner): \Illuminate\Testing\TestResponse
    {
        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Personal Loan Application',
            'is_active' => true,
        ]);

        return $this->get(self::WEBSITE_URL . '/form/' . $config->public_key);
    }

    private function partner(string $type): Partner
    {
        return Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => $type,
            'company_name' => 'Partner ' . uniqid(),
            'full_name' => 'Partner Contact',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);
    }

    /**
     * @param  array<string, string>  $logos
     */
    private function brand(Partner $partner, array $logos): void
    {
        foreach ($logos as $key => $value) {
            AppConfiguration::create([
                'partner_id' => $partner->id,
                'module' => AppConfiguration::MODULE_BRANDING,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }
}
