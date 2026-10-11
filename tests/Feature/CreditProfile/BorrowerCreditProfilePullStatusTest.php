<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the client sees about their own credit pulls: when it was last pulled,
 * and where their latest request stands.
 *
 * Both come off the authorisation records. A request is the consent the client
 * gave; it is pending until a pull is attached to it (`pulled_at`), and
 * completed after. Times are converted to the client's own time zone here, from
 * X-Timezone, so the portal only displays them.
 */
class BorrowerCreditProfilePullStatusTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host Partner LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        BorrowerCreditProfile::create(['borrower_id' => $this->borrower->id, 'fico_score' => 700]);

        Passport::actingAs(User::create([
            'name' => 'Odessa Mccall',
            'email' => 'odessa@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'partner_id' => $partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]));
    }

    private function consent(string $consentedAt, ?string $pulledAt): CreditAuthorizationConsent
    {
        return CreditAuthorizationConsent::create([
            'borrower_id' => $this->borrower->id,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'I authorize a soft credit inquiry.',
            'e_consent_text' => 'I agree to sign electronically.',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => Carbon::parse($consentedAt, 'UTC'),
            'pulled_at' => $pulledAt ? Carbon::parse($pulledAt, 'UTC') : null,
        ]);
    }

    private function pullStatus(string $timezone = 'Asia/Dhaka'): array
    {
        return $this->getJson('/api/v1/borrower/profile/credit-profile', ['X-Timezone' => $timezone])
            ->assertOk()
            ->json('data.pull_status');
    }

    #[Test]
    public function a_request_with_no_pull_yet_is_pending(): void
    {
        $this->consent('2026-09-20 10:00:00', null);

        $status = $this->pullStatus();

        $this->assertSame('pending', $status['last_request']['status']);
        $this->assertSame('Sep 20, 2026 04:00 PM', $status['last_request']['requested_at']);
        $this->assertNull($status['last_pulled_at']);
    }

    #[Test]
    public function a_request_that_was_pulled_is_completed(): void
    {
        $this->consent('2026-09-20 10:00:00', '2026-09-21 20:30:00');

        $status = $this->pullStatus();

        $this->assertSame('completed', $status['last_request']['status']);
        $this->assertSame('Sep 22, 2026 02:30 AM', $status['last_pulled_at']);
    }

    #[Test]
    public function the_latest_request_decides_the_status_and_the_last_pull_is_the_latest_pull(): void
    {
        $this->consent('2026-08-01 10:00:00', '2026-08-02 10:00:00'); // older, completed
        $this->consent('2026-09-25 10:00:00', null);                 // newer, still waiting

        $status = $this->pullStatus();

        $this->assertSame('pending', $status['last_request']['status']);
        $this->assertSame('Aug 02, 2026 04:00 PM', $status['last_pulled_at']);
    }

    #[Test]
    public function it_has_nothing_to_report_when_there_is_no_record(): void
    {
        $status = $this->pullStatus();

        $this->assertNull($status['last_request']);
        $this->assertNull($status['last_pulled_at']);
    }

    #[Test]
    public function it_does_not_leak_another_clients_records(): void
    {
        $other = Borrower::create([
            'partner_id' => $this->borrower->partner_id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'email' => 'else@example.test',
        ]);
        CreditAuthorizationConsent::create([
            'borrower_id' => $other->id,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'x',
            'e_consent_text' => 'y',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => Carbon::parse('2026-09-30 10:00:00', 'UTC'),
            'pulled_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        ]);

        $this->assertNull($this->pullStatus()['last_pulled_at']);
    }
}
