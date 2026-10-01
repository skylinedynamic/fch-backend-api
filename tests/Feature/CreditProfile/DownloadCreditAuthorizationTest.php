<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The FCRA authorisation PDF, which iSoftPull can ask for months after a pull.
 *
 * The endpoint had no test of its own, which is part of why a wrong path in the
 * partner portal went unnoticed: nothing here said what the route was, so
 * nothing disagreed when the caller asked for a different one.
 *
 * Two things it has to keep doing. It is host-partner only - these records
 * carry the consumer's IP and device, and are not for every partner who can see
 * a client. And it carries the tenant's own logo, embedded rather than linked,
 * because Dompdf fetches no remote images and this document should not be the
 * reason remote fetching gets turned on.
 */
class DownloadCreditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Borrower $borrower;
    private CreditAuthorizationConsent $consent;

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

        $this->borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        // The wording is copied into the record rather than referenced, so a
        // later change to the published document cannot rewrite what this
        // client agreed to - which is why these are not nullable.
        $this->consent = CreditAuthorizationConsent::create([
            'borrower_id' => $this->borrower->id,
            'authorization_version' => '2026-01',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'I authorize a soft credit inquiry.',
            'e_consent_text' => 'I agree to sign electronically.',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => now(),
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
        ]);
    }

    private function actAsHost(): void
    {
        Passport::actingAs(User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $this->host->id,
        ]));
    }

    private function actAsOtherPartner(): void
    {
        $other = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Some Reseller',
            'full_name' => 'Reseller Owner',
            'email' => 'reseller@example.test',
        ]);

        Passport::actingAs(User::create([
            'name' => 'Reseller Admin',
            'email' => 'reseller-admin@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'partner_id' => $other->id,
        ]));
    }

    private function download(?int $id = null)
    {
        return $this->get('/api/v1/common/credit-authorizations/' . ($id ?? $this->consent->id) . '/download');
    }

    // -- the route the portal has to call -----------------------------------

    #[Test]
    public function the_host_gets_the_record_as_a_pdf(): void
    {
        $this->actAsHost();

        $response = $this->download();

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function the_file_is_named_for_the_client_and_the_record(): void
    {
        // So a folder of these stays sortable and unambiguous in an audit reply.
        $this->actAsHost();

        $this->download()->assertHeader(
            'content-disposition',
            'attachment; filename=credit-authorization-client-'
                . $this->borrower->id . '-record-' . $this->consent->id . '.pdf'
        );
    }

    #[Test]
    public function a_record_that_does_not_exist_is_a_404(): void
    {
        $this->actAsHost();

        $this->download(999999)->assertStatus(404);
    }

    // -- who may read a consumer's IP and device ----------------------------

    #[Test]
    public function another_partner_is_refused(): void
    {
        $this->actAsOtherPartner();

        $this->download()->assertStatus(403);
    }

    #[Test]
    public function an_unauthenticated_caller_is_refused(): void
    {
        // Asked for as an API client would: without Accept: application/json the
        // guard redirects to a login page instead of answering 401, which is
        // the framework's default and not what the portal ever sees.
        $this->getJson('/api/v1/common/credit-authorizations/' . $this->consent->id . '/download')
            ->assertStatus(401);
    }

    // -- the tenant's mark on the document ----------------------------------

    #[Test]
    public function it_still_renders_when_the_tenant_has_uploaded_no_logo(): void
    {
        // No logo is fine; a broken image box in an audit document is not.
        $this->actAsHost();

        $this->download()->assertOk();
    }

    #[Test]
    public function a_stored_logo_does_not_stop_the_document_rendering(): void
    {
        // A real PNG, because Dompdf decodes it rather than taking the data URI
        // on trust - a placeholder string here would throw during render.
        Storage::disk('public')->put('branding/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        AppConfiguration::create([
            'partner_id' => $this->host->id,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'logo_document',
            'value' => 'branding/logo.png',
        ]);

        $this->actAsHost();

        $response = $this->download();

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }
}
