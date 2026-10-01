<?php

namespace Tests\Feature\CreditProfile;

use App\Http\Controllers\Api\V1\FCH\Common\CreditAuthorization\CreditAuthorizationConsentController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Whose mark goes on the FCRA authorisation PDF.
 *
 * The borrower's own partner, and only ever theirs. getBrandingSettings()
 * inherits logos from the host by default - correct for a Certified partner,
 * which trades under the host's brand deliberately - but this document must not
 * take that route: a white-label tenant's paperwork carrying the platform's
 * logo announces exactly what the tier exists to hide, and iSoftPull is being
 * told this record belongs to that tenant.
 *
 * So a tenant with no logo of its own produces a document with no logo, not one
 * with someone else's.
 */
class ConsentPdfLogoTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Four Corner Host',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);
    }

    private function storeLogo(string $path, string $contents): void
    {
        Storage::disk('public')->put($path, $contents);
    }

    private function brandLogo(int $partnerId, string $path): void
    {
        AppConfiguration::create([
            'partner_id' => $partnerId,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'logo_document',
            'value' => $path,
        ]);
    }

    private function partner(string $name, bool $isHost = false): Partner
    {
        return Partner::create([
            'is_host' => $isHost,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => $name,
            'full_name' => $name . ' Owner',
            'email' => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        ]);
    }

    private function borrowerOf(Partner $partner): Borrower
    {
        return Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
    }

    /** The logo the PDF would draw for this borrower, as its embedded bytes. */
    private function logoFor(Borrower $borrower): ?string
    {
        $method = new ReflectionMethod(CreditAuthorizationConsentController::class, 'logoFor');
        $method->setAccessible(true);

        $src = $method->invoke(app(CreditAuthorizationConsentController::class), $borrower);

        return $src === null ? null : base64_decode(explode(',', $src)[1]);
    }

    // -- the borrower's own partner ----------------------------------------

    #[Test]
    public function it_uses_the_borrowers_own_partners_logo(): void
    {
        $tenant = $this->partner('Exodias Capital');
        $this->storeLogo('branding/exodias.png', 'exodias-logo');
        $this->brandLogo($tenant->id, 'branding/exodias.png');

        $this->assertSame('exodias-logo', $this->logoFor($this->borrowerOf($tenant)));
    }

    #[Test]
    public function the_hosts_own_borrowers_get_the_hosts_logo(): void
    {
        // Not inheritance - it is their own partner, which happens to be the
        // host. The rule is "the borrower's partner", not "never the host".
        $this->storeLogo('branding/host.png', 'host-logo');
        $this->brandLogo($this->host->id, 'branding/host.png');

        $this->assertSame('host-logo', $this->logoFor($this->borrowerOf($this->host)));
    }

    // -- and never the host's, by inheritance -------------------------------

    #[Test]
    public function a_tenant_without_a_logo_does_not_borrow_the_hosts(): void
    {
        // This is the one that matters. getBrandingSettings() would hand over
        // the host's logo here, and the document would go to iSoftPull carrying
        // the platform's mark on a white-label tenant's record.
        $this->storeLogo('branding/host.png', 'host-logo');
        $this->brandLogo($this->host->id, 'branding/host.png');

        $tenant = $this->partner('Exodias Capital');

        $this->assertNull($this->logoFor($this->borrowerOf($tenant)));
    }

    #[Test]
    public function a_tenants_own_logo_is_preferred_over_the_hosts(): void
    {
        $this->storeLogo('branding/host.png', 'host-logo');
        $this->brandLogo($this->host->id, 'branding/host.png');

        $tenant = $this->partner('Exodias Capital');
        $this->storeLogo('branding/exodias.png', 'exodias-logo');
        $this->brandLogo($tenant->id, 'branding/exodias.png');

        $this->assertSame('exodias-logo', $this->logoFor($this->borrowerOf($tenant)));
    }

    // -- nothing to draw ----------------------------------------------------

    #[Test]
    public function a_borrower_with_no_partner_draws_nothing(): void
    {
        $orphan = Borrower::create([
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'No',
            'last_name' => 'Partner',
            'email' => 'orphan@example.test',
        ]);

        $this->assertNull($this->logoFor($orphan));
    }

    #[Test]
    public function a_missing_borrower_draws_nothing(): void
    {
        $method = new ReflectionMethod(CreditAuthorizationConsentController::class, 'logoFor');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(app(CreditAuthorizationConsentController::class), null));
    }
}
