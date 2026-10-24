<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /borrower/active-funding-requests: the approved funding
 * requests no application has been started for. The portal's layout (banner
 * and sidebar badge) reads only this, so it no longer loads the whole dashboard
 * on every page.
 */
class BorrowerActiveFundingRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
        $this->borrower = $this->makeBorrower();
    }

    #[Test]
    public function it_returns_the_approved_requests_without_an_application_latest_first(): void
    {
        $sequenceId = $this->makeSequence('10000-250000');
        $older = $this->makeRequest(['funding_sequence_id' => $sequenceId, 'created_at' => now()->subDays(2)]);
        $newer = $this->makeRequest(['funding_type' => 'Line of Credit', 'requested_amount' => 45000, 'created_at' => now()->subDay()]);

        $this->actingAsBorrower($this->borrower);

        $this->getJson('/api/v1/borrower/active-funding-requests')
            ->assertOk()
            ->assertJsonPath('data.active_approved_funding_request_count', 2)
            ->assertJsonPath('data.active_approved_funding_requests.0.id', $newer)
            ->assertJsonPath('data.active_approved_funding_requests.1.id', $older)
            ->assertJsonPath('data.active_approved_funding_request.id', $newer)
            ->assertJsonPath('data.active_approved_funding_request.funding_type', 'Line of Credit')
            ->assertJsonPath('data.active_approved_funding_request.estimated_funding_range', currencyFormat(45000, 0))
            ->assertJsonPath('data.active_approved_funding_requests.1.estimated_funding_range', formatAmountRange('10000-250000'))
            // Only what the layout needs: nothing of the dashboard comes along.
            ->assertJsonMissingPath('data.states')
            ->assertJsonMissingPath('data.latest_funding_applications');
    }

    #[Test]
    public function requests_already_started_or_not_approved_are_left_out(): void
    {
        $sequenceId = $this->makeSequence('10000-250000');
        $started = $this->makeRequest();
        $this->makeApplication(['funding_request_id' => $started]);
        // An application on the same funding sequence covers the request too.
        $sameSequence = $this->makeRequest(['funding_sequence_id' => $sequenceId]);
        $this->makeApplication(['funding_sequence_id' => $sequenceId]);
        $this->makeRequest(['status' => FundingRequest::STATUS_PENDING]);
        $this->makeRequest(['status' => FundingRequest::STATUS_REJECTED]);
        $open = $this->makeRequest();

        $this->actingAsBorrower($this->borrower);

        $response = $this->getJson('/api/v1/borrower/active-funding-requests')->assertOk();

        $this->assertSame([$open], array_column($response->json('data.active_approved_funding_requests'), 'id'));
        $this->assertNotContains($sameSequence, array_column($response->json('data.active_approved_funding_requests'), 'id'));
        $response->assertJsonPath('data.active_approved_funding_request_count', 1);
    }

    /**
     * Defect: every funding request is now a new one, but an application on an
     * older request for the same funding option (here On Hold) hid the newly
     * approved request, so the "approved" banner never showed. Only an
     * application on this request - or one linked to no request at all on the
     * same option - means it has been started.
     */
    #[Test]
    public function an_application_on_an_older_request_does_not_hide_a_newly_approved_one(): void
    {
        $sequenceId = $this->makeSequence('10000-250000');
        $older = $this->makeRequest(['funding_sequence_id' => $sequenceId, 'created_at' => now()->subDay()]);
        $this->makeApplication([
            'funding_request_id' => $older,
            'funding_sequence_id' => $sequenceId,
            'status' => FundingApplication::ON_HOLD,
        ]);
        $newlyApproved = $this->makeRequest(['funding_sequence_id' => $sequenceId]);

        $this->actingAsBorrower($this->borrower);

        $this->getJson('/api/v1/borrower/active-funding-requests')
            ->assertOk()
            ->assertJsonPath('data.active_approved_funding_request.id', $newlyApproved)
            ->assertJsonPath('data.active_approved_funding_request_count', 1);
    }

    #[Test]
    public function a_deleted_application_does_not_hide_its_request(): void
    {
        $request = $this->makeRequest();
        $this->makeApplication(['funding_request_id' => $request, 'deleted_at' => now()]);

        $this->actingAsBorrower($this->borrower);

        $this->getJson('/api/v1/borrower/active-funding-requests')
            ->assertOk()
            ->assertJsonPath('data.active_approved_funding_request.id', $request);
    }

    #[Test]
    public function another_borrowers_requests_are_never_returned(): void
    {
        $other = $this->makeBorrower();
        $this->makeRequest(['borrower_id' => $other->id]);

        $this->actingAsBorrower($this->borrower);

        $this->getJson('/api/v1/borrower/active-funding-requests')
            ->assertOk()
            ->assertJsonPath('data.active_approved_funding_requests', [])
            ->assertJsonPath('data.active_approved_funding_request', null)
            ->assertJsonPath('data.active_approved_funding_request_count', 0);
    }

    #[Test]
    public function it_needs_a_signed_in_borrower(): void
    {
        $this->getJson('/api/v1/borrower/active-funding-requests')->assertUnauthorized();
    }

    #[Test]
    public function the_dashboard_leaves_the_active_requests_to_their_own_endpoint(): void
    {
        $this->makeRequest();

        $this->actingAsBorrower($this->borrower);

        // The portal reads them from active-funding-requests on every page,
        // the dashboard included, so the dashboard no longer queries them.
        $this->getJson('/api/v1/borrower/dashboard')
            ->assertOk()
            ->assertJsonPath('data.states.funding_requests', 1)
            ->assertJsonMissingPath('data.active_approved_funding_requests')
            ->assertJsonMissingPath('data.active_approved_funding_request')
            ->assertJsonMissingPath('data.active_approved_funding_request_count');
    }

    private function makeBorrower(): Borrower
    {
        return Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);
    }

    private function makeSequence(?string $amountRange): int
    {
        return DB::table('funding_sequencings')->insertGetId([
            'name' => 'Term Loans',
            'subtitle' => 'Test sequence',
            'amount_range' => $amountRange,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeRequest(array $overrides = []): int
    {
        // Every request belongs to a funding sequence; by default its own, with
        // no amount range, so the requested amount is the estimate.
        return DB::table('funding_requests')->insertGetId(array_merge([
            'funding_sequence_id' => $overrides['funding_sequence_id'] ?? $this->makeSequence(null),
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makeApplication(array $overrides = []): int
    {
        return DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-TEST-' . uniqid(),
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function actingAsBorrower(Borrower $borrower): void
    {
        Passport::actingAs(User::create([
            'name' => 'Odessa Mccall',
            'email' => 'login-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
        ]), [], 'api');
    }
}
