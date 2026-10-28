<?php

namespace Tests\Feature\FundApplication;

use App\Http\Resources\Borrower\Dashboard\DashboardFundingApplicationResource;
use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource as MyApplicationResource;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect (BUG-136): an application the client started but did not submit
 * (On Hold, saved up to step 2) showed Requested, Funding Type and Purpose as
 * "—" / "N/A" - on Clients > Funding Applications, on My Applications and on
 * the client dashboard - although its funding request already says
 * $40,000 / Revenue Based Financing. The application has not filled those in
 * yet; until it does, it shows them from its funding request.
 */
#[CoversClass(FundingApplication::class)]
#[CoversClass(FundingApplicationResource::class)]
#[CoversClass(MyApplicationResource::class)]
#[CoversClass(DashboardFundingApplicationResource::class)]
class OnHoldApplicationShowsItsFundingRequestTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private FundingApplication $onHold;

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner();
        $this->admin = $this->makePartnerAdmin($host);
        $this->client = $this->makeBorrowerUser($host);

        $request = FundingRequest::create([
            'partner_id' => $host->id,
            'funding_sequence_id' => DB::table('funding_sequencings')->insertGetId([
                'name' => 'Revenue Based Financing', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
            ]),
            'borrower_id' => $this->client->entity_id,
            'funding_type' => 'Revenue Based Financing',
            'requested_amount' => 40000,
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
        ]);

        // Started from the request, saved up to step 2: nothing about the funding yet.
        $this->onHold = FundingApplication::forceCreate([
            'partner_id' => $host->id,
            'borrower_id' => $this->client->entity_id,
            'funding_request_id' => $request->id,
            'status' => FundingApplication::ON_HOLD,
        ]);
    }

    #[Test]
    public function the_clients_funding_applications_tab_shows_the_amount_type_and_purpose_of_its_request(): void
    {
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/request-application/funding-application', ['borrower_id' => $this->client->entity_id])
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->onHold->id)
            ->assertJsonPath('data.0.requested_amount', '$40,000.00')
            ->assertJsonPath('data.0.preferred_funding_type', 'Revenue Based Financing')
            ->assertJsonPath('data.0.purpose_of_funds', 'Working Capital');
    }

    #[Test]
    public function the_application_details_show_them_too(): void
    {
        $this->actingAsUser($this->admin);

        $this->getJson("/api/v1/common/request-application/funding-application/{$this->onHold->id}")
            ->assertOk()
            ->assertJsonPath('data.requested_amount', '$40,000.00')
            ->assertJsonPath('data.preferred_funding_type', 'Revenue Based Financing')
            ->assertJsonPath('data.purpose_of_funds', 'Working Capital');
    }

    #[Test]
    public function the_client_sees_them_on_my_applications(): void
    {
        $this->actingAsUser($this->client);

        $this->postJson('/api/v1/borrower/myapplication/funding-application')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->onHold->id)
            ->assertJsonPath('data.0.requested_amount', '$40,000.00')
            ->assertJsonPath('data.0.preferred_funding_type', 'Revenue Based Financing')
            ->assertJsonPath('data.0.purpose_of_funds', 'Working Capital');
    }

    #[Test]
    public function the_client_dashboard_shows_the_amount_and_counts_it_in_the_total_requested(): void
    {
        $this->actingAsUser($this->client);

        $this->getJson('/api/v1/borrower/dashboard')
            ->assertOk()
            ->assertJsonPath('data.latest_funding_applications.0.requested_amount', fn ($value) => (float) $value === 40000.0)
            ->assertJsonPath('data.latest_funding_applications.0.purpose_of_funds', 'Working Capital')
            ->assertJsonPath('data.states.total_requested_amount', fn ($value) => (float) $value === 40000.0);
    }

    #[Test]
    public function once_the_application_has_its_own_values_they_are_shown_instead(): void
    {
        $this->onHold->forceFill([
            'requested_amount' => 35000,
            'preferred_funding_type' => 'Term Loan',
            'purpose_of_funds' => 'Equipment',
        ])->save();
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/request-application/funding-application', ['borrower_id' => $this->client->entity_id])
            ->assertOk()
            ->assertJsonPath('data.0.requested_amount', '$35,000.00')
            ->assertJsonPath('data.0.preferred_funding_type', 'Term Loan')
            ->assertJsonPath('data.0.purpose_of_funds', 'Equipment');
    }

    #[Test]
    public function an_application_with_no_funding_request_still_shows_nothing(): void
    {
        $this->onHold->forceFill(['funding_request_id' => null])->save();
        $this->actingAsUser($this->admin);

        $this->postJson('/api/v1/common/request-application/funding-application', ['borrower_id' => $this->client->entity_id])
            ->assertOk()
            ->assertJsonPath('data.0.requested_amount', null)
            ->assertJsonPath('data.0.preferred_funding_type', null)
            ->assertJsonPath('data.0.purpose_of_funds', null);
    }
}
