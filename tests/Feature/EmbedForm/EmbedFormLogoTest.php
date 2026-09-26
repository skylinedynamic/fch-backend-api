<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Support\EmbedForm\EmbedFormLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whose logo the public application form shows.
 *
 * The form is a lead-capture page carrying a brand, and which brand depends on
 * what the partner is:
 *
 *   White-Label partner - its own logo, and only its own. The tier exists to
 *                         keep the platform invisible, so borrowing the host's
 *                         mark here would advertise the very company the partner
 *                         is paying not to show.
 *   Certified / host    - the host's logo. A Certified partner trades under the
 *                         host's brand, so the host's mark is the correct one
 *                         even when the partner has uploaded something itself.
 *
 * Within whichever tenant owns the brand, the surfaces are tried
 * logo_document -> logo_mail -> logo_sidebar: the form renders on white, so the
 * document logo is first and the (dark) sidebar logo is the last resort.
 *
 * NOTE the cache version bump in setUp. Branding is memoised in a method-static
 * for the life of the PHP process, and a fresh database restarts partner ids at
 * 1, so without a distinct version per test one test reads the previous test's
 * logos back out of the memo.
 */
#[CoversClass(EmbedFormLogo::class)]
class EmbedFormLogoTest extends TestCase
{
    use RefreshDatabase;

    private static int $versionSeed = 100000;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        // A wide band per test, not +1: writing a branding row bumps the version
        // itself, so adjacent seeds would otherwise meet in the middle and two
        // tests would share a memo key.
        Cache::forever('branding:version', self::$versionSeed += 1000);

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
    }

    #[Test]
    public function a_white_label_partner_shows_its_own_document_logo(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL);

        $this->brand($this->host, ['logo_document' => 'site-settings/host-doc.png']);
        $this->brand($partner, ['logo_document' => 'partners/2/wl-doc.png']);

        $this->assertSame('partners/2/wl-doc.png', $this->resolve($partner));
    }

    #[Test]
    public function a_white_label_partner_does_not_borrow_the_hosts_logo(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_WHITE_LABEL);

        $this->brand($this->host, ['logo_document' => 'site-settings/host-doc.png']);

        $this->assertNull($this->resolve($partner));
    }

    #[Test]
    public function a_certified_partner_shows_the_hosts_logo_over_its_own_upload(): void
    {
        $partner = $this->partner(Partner::PARTNER_TYPE_CERTIFIED);

        $this->brand($this->host, ['logo_document' => 'site-settings/host-doc.png']);
        $this->brand($partner, ['logo_document' => 'partners/2/certified-doc.png']);

        $this->assertSame('site-settings/host-doc.png', $this->resolve($partner));
    }

    #[Test]
    public function the_host_partner_shows_its_own_logo(): void
    {
        $this->brand($this->host, ['logo_document' => 'site-settings/host-doc.png']);

        $this->assertSame('site-settings/host-doc.png', $this->resolve($this->host));
    }

    #[Test]
    public function a_form_with_no_partner_shows_the_hosts_logo(): void
    {
        $this->brand($this->host, ['logo_document' => 'site-settings/host-doc.png']);

        $this->assertSame('site-settings/host-doc.png', $this->resolve(null));
    }

    #[Test]
    public function mail_is_preferred_over_the_dark_sidebar_logo(): void
    {
        $this->brand($this->host, [
            'logo_mail' => 'site-settings/host-mail.png',
            'logo_sidebar' => 'site-settings/host-sidebar.png',
        ]);

        $this->assertSame('site-settings/host-mail.png', $this->resolve(null));
    }

    #[Test]
    public function the_sidebar_logo_is_used_when_it_is_all_there_is(): void
    {
        $this->brand($this->host, ['logo_sidebar' => 'site-settings/host-sidebar.png']);

        $this->assertSame('site-settings/host-sidebar.png', $this->resolve(null));
    }

    #[Test]
    public function a_tenant_with_no_logos_at_all_resolves_to_nothing(): void
    {
        // The callers skip the <img> entirely rather than render a broken one.
        $this->assertNull($this->resolve(null));
    }

    private function resolve(?Partner $partner): ?string
    {
        return app(EmbedFormLogo::class)->pathFor($partner);
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
