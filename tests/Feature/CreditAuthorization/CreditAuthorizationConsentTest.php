<?php

namespace Tests\Feature\CreditAuthorization;

use App\Models\Borrower\Borrower;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use App\Services\Borrower\Profile\CreditAuthorizationConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The record that proves a client asked us to pull their credit.
 *
 * Under the FCRA a consumer report may only be pulled on the consumer's own
 * written instructions, and iSoftPull can ask us months later to produce them.
 * A sentence on a page proves nothing: what has to survive is an artefact naming
 * who agreed, to exactly what wording, when, and from where - and tying that to
 * the pull it authorised.
 *
 * Two points here are deliberate and easy to get wrong:
 *
 *  - the wording is copied INTO the record, not referenced. The published
 *    documents get new version numbers rather than edits, so the stored text
 *    plus the stored version is what the client actually saw, whatever the site
 *    says today.
 *  - `consented_at` is never rewritten. The pull happens later and gets its own
 *    `pulled_at`; overwriting the first would destroy the very thing being
 *    proved.
 */
class CreditAuthorizationConsentTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
    }

    private function request(): Request
    {
        $request = Request::create('/borrower/credit-profile-request', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.42',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TestAgent/1.0',
        ]);

        return $request;
    }

    private function record(?int $creditProfileRequestId = null): CreditAuthorizationConsent
    {
        return app(CreditAuthorizationConsentService::class)
            ->record($this->borrower->id, $this->request(), $creditProfileRequestId);
    }

    #[Test]
    public function it_stores_who_agreed_and_from_where(): void
    {
        $consent = $this->record();

        $this->assertSame($this->borrower->id, $consent->borrower_id);
        $this->assertSame('203.0.113.42', $consent->ip_address);
        $this->assertStringContainsString('TestAgent/1.0', $consent->user_agent);
        $this->assertNotNull($consent->consented_at);
    }

    #[Test]
    public function it_records_both_acceptances_separately(): void
    {
        // Two legally distinct consents - FCRA authorisation and E-SIGN - so one
        // boolean for the pair would not show which was given.
        $consent = $this->record();

        $this->assertTrue((bool) $consent->credit_authorization_accepted);
        $this->assertTrue((bool) $consent->e_consent_accepted);
    }

    #[Test]
    public function it_stores_the_version_as_the_exact_string(): void
    {
        // '1.0', not 1.0 - a float would render as "1" and stop matching the
        // version history published on the authorisation page.
        $consent = $this->record();

        $this->assertSame('1.0', $consent->authorization_version);
        $this->assertIsString($consent->authorization_version);
    }

    #[Test]
    public function it_copies_the_wording_the_client_was_shown(): void
    {
        $consent = $this->record();

        $this->assertStringContainsString('Fair Credit Reporting Act', $consent->credit_authorization_text);
        $this->assertStringContainsString('Four Corner Holdings, LLC', $consent->credit_authorization_text);
        $this->assertStringContainsString('Equifax, TransUnion, and/or Experian', $consent->credit_authorization_text);
        $this->assertStringContainsString('Electronic Disclosures and Communications', $consent->e_consent_text);
    }

    #[Test]
    public function it_cites_the_public_copy_of_the_authorization(): void
    {
        // The PDF sent to iSoftPull points here, so anyone can read the exact
        // wording and its effective date rather than taking our PDF's word.
        $consent = $this->record();

        $this->assertSame(
            'https://fourcornerholdings.com/credit-authorization',
            $consent->authorization_url
        );
    }

    #[Test]
    public function a_pull_adds_its_own_timestamp_and_never_moves_the_consent(): void
    {
        $consent = $this->record();
        $consentedAt = $consent->consented_at;

        app(CreditAuthorizationConsentService::class)
            ->attachPull($consent, 'ISP-TXN-991188');

        $consent->refresh();

        $this->assertSame('ISP-TXN-991188', $consent->isoftpull_transaction_id);
        $this->assertNotNull($consent->pulled_at);
        $this->assertEquals($consentedAt, $consent->consented_at);
    }

    #[Test]
    public function every_request_gets_its_own_record(): void
    {
        // Consent is per request, not once forever: the client ticks the boxes
        // again each time they ask for an update, and each pull needs its own
        // proof.
        $this->record();
        $this->record();

        $this->assertSame(2, CreditAuthorizationConsent::where('borrower_id', $this->borrower->id)->count());
    }

    #[Test]
    public function it_links_to_the_request_it_authorised_when_there_is_one(): void
    {
        $consent = $this->record(4242);

        $this->assertSame(4242, $consent->credit_profile_request_id);
    }
}
