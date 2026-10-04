<?php

namespace Tests\Feature\CreditProfile;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\CreditProfileRequest;
use App\Models\CreditAuthorization\CreditAuthorizationConsent;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Request Credit Profile list says whether each client is still waiting.
 *
 * A request is the consent the client gave; it is pending until a pull is
 * attached to it (`pulled_at`). The client's latest consent decides. A request
 * from before consents were recorded has none, so it falls back to whether a
 * credit profile has been saved for the client - that is the pull having run.
 */
class CreditProfileRequestListStatusTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Four Corner Host',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        Passport::actingAs(User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::ADMIN,
            'partner_id' => $this->host->id,
        ]));
    }

    private function clientWithRequest(string $email): Borrower
    {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Client',
            'last_name' => $email,
            'email' => $email,
        ]);

        CreditProfileRequest::create([
            'borrower_id' => $borrower->id,
            'first_name' => 'Client',
            'last_name' => $email,
            'address' => '1 Main St',
            'city' => 'Austin',
            'state' => 'Texas',
            'zip' => '78701',
            'ssn' => '123456789',
            'date_of_birth' => '1990-01-01',
            'phone' => '5125550100',
            'email' => $email,
        ]);

        return $borrower;
    }

    private function consent(Borrower $borrower, string $consentedAt, ?string $pulledAt): void
    {
        CreditAuthorizationConsent::create([
            'borrower_id' => $borrower->id,
            'authorization_version' => '1.0',
            'authorization_url' => 'https://example.test/disclosure',
            'credit_authorization_text' => 'x',
            'e_consent_text' => 'y',
            'credit_authorization_accepted' => true,
            'e_consent_accepted' => true,
            'consented_at' => Carbon::parse($consentedAt, 'UTC'),
            'pulled_at' => $pulledAt ? Carbon::parse($pulledAt, 'UTC') : null,
        ]);
    }

    /** @return array<string, ?string> request status keyed by the row's email */
    private function statuses(): array
    {
        $rows = $this->postJson('/api/v1/admin/credit-profile-requests', [])
            ->assertOk()
            ->json('data.data');

        return collect($rows)->mapWithKeys(fn ($row) => [$row['email'] => $row['request_status']])->all();
    }

    #[Test]
    public function a_client_whose_latest_request_has_not_been_pulled_is_pending(): void
    {
        $client = $this->clientWithRequest('waiting@example.test');
        $this->consent($client, '2026-08-01 10:00:00', '2026-08-02 10:00:00');
        $this->consent($client, '2026-09-25 10:00:00', null); // asked again, not pulled yet

        $this->assertSame('pending', $this->statuses()['waiting@example.test']);
    }

    #[Test]
    public function a_client_whose_latest_request_was_pulled_is_completed(): void
    {
        $client = $this->clientWithRequest('done@example.test');
        $this->consent($client, '2026-09-20 10:00:00', '2026-09-21 10:00:00');

        $this->assertSame('completed', $this->statuses()['done@example.test']);
    }

    #[Test]
    public function an_older_request_without_consents_is_completed_once_a_profile_is_saved(): void
    {
        $client = $this->clientWithRequest('legacy-done@example.test');
        BorrowerCreditProfile::create(['borrower_id' => $client->id, 'fico_score' => 700]);

        $this->assertSame('completed', $this->statuses()['legacy-done@example.test']);
    }

    #[Test]
    public function an_older_request_without_consents_or_a_profile_is_pending(): void
    {
        $this->clientWithRequest('legacy-waiting@example.test');

        $this->assertSame('pending', $this->statuses()['legacy-waiting@example.test']);
    }

    #[Test]
    public function each_client_gets_their_own_status(): void
    {
        $waiting = $this->clientWithRequest('a@example.test');
        $this->consent($waiting, '2026-09-25 10:00:00', null);
        $done = $this->clientWithRequest('b@example.test');
        $this->consent($done, '2026-09-20 10:00:00', '2026-09-21 10:00:00');

        $this->assertSame(
            ['a@example.test' => 'pending', 'b@example.test' => 'completed'],
            collect($this->statuses())->sortKeys()->all()
        );
    }
}
