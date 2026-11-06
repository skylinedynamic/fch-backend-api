<?php

namespace Tests\Feature\FundApplication;

use App\Http\Resources\FCH\Partner\FundApplication\FundingRequestResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\PartnerFundApplication\PartnerFundingApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\Support\CreatesFundingTypes;
use Tests\TestCase;

/**
 * Defect: an application converted from the embed form has no funding request
 * (funding_request_id and funding_sequence_id are null), and the admin/partner
 * edit wizard could neither open nor save it:
 *  - every wizard read looked the application up by funding_request_id only;
 *  - a save that sent only the application's `id` reused that id as a funding
 *    request id (404 "funding request not found"), or, when none was found,
 *    attached the borrower's latest approved funding request to it;
 *  - the step resource guessed a funding request from auth()->user()->entity_id,
 *    which for a partner login is the partner's id, not a borrower's.
 * The wizard now reads by funding_application_id, and a save of an existing
 * application keeps the funding request it has - none stays none.
 */
#[CoversClass(PartnerFundingApplicationService::class)]
#[CoversClass(FundingRequestResource::class)]
class PartnerEditsApplicationWithoutFundingRequestTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;
    use CreatesFundingTypes;

    private Partner $host;

    private Borrower $borrower;

    private int $applicationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->borrower = $this->makeBorrower($this->host, ['first_name' => 'Jimmy', 'last_name' => 'Thornton']);
        $this->applicationId = $this->applicationWithoutRequest($this->borrower);
    }

    #[Test]
    public function the_wizard_reads_an_application_without_a_funding_request_by_its_own_id(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        foreach (['business-info', 'ownership-info', 'financial-info', 'funding-request', 'disclosures-and-consent', 'federal-compliance-disclosures'] as $step) {
            $this->getJson("/api/v1/common/funding-application/{$step}?funding_application_id={$this->applicationId}")
                ->assertOk()
                ->assertJsonPath('data.id', $this->applicationId);
        }
    }

    #[Test]
    public function the_wizard_does_not_guess_a_funding_request_for_an_application_that_has_none(): void
    {
        // An approved request belonging to a borrower whose id is the partner's
        // id - what the resource used to find through the partner's entity_id.
        $stranger = $this->makeBorrower($this->host);
        DB::table('borrowers')->where('id', $stranger->id)->update(['id' => 9000 + $this->host->id]);
        $this->approvedRequestFor(9000 + $this->host->id);
        $admin = $this->makePartnerAdmin($this->host);
        DB::table('users')->where('id', $admin->id)->update(['entity_id' => 9000 + $this->host->id]);
        $this->actingAsUser($admin->fresh());

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_application_id={$this->applicationId}")
            ->assertOk()
            ->assertJsonPath('data.funding_request_id', null)
            ->assertJsonPath('data.funding_sequence_id', null);
    }

    #[Test]
    public function another_partner_cannot_read_the_application_by_its_id(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));

        $this->getJson("/api/v1/common/funding-application/business-info?funding_application_id={$this->applicationId}")
            ->assertNotFound();
    }

    #[Test]
    public function saving_a_step_keeps_the_application_without_a_funding_request(): void
    {
        // The borrower has an approved request of their own, and one whose id is
        // the application's id: neither may be attached by this save.
        $this->approvedRequestFor($this->borrower->id);
        $this->approvedRequestFor($this->borrower->id, $this->applicationId);

        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/funding-application/funding-request', [
            'id' => $this->applicationId,
            'requested_amount' => 40000,
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => 'Term Loans',
            'preferred_funding_type' => 'Term Loan',
        ])->assertOk()->assertJsonPath('data.id', $this->applicationId);

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertSame(40000.0, (float) $application->requested_amount);
        $this->assertNull($application->funding_request_id);
        $this->assertNull($application->funding_sequence_id);
        $this->assertSame(1, FundingApplication::where('borrower_id', $this->borrower->id)->count());
    }

    #[Test]
    public function an_application_with_a_funding_request_is_still_read_by_that_request(): void
    {
        $requestId = $this->approvedRequestFor($this->borrower->id);
        DB::table('funding_applications')->where('id', $this->applicationId)->update(['funding_request_id' => $requestId]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_request_id={$requestId}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->applicationId)
            ->assertJsonPath('data.funding_request_id', $requestId);
    }

    private function applicationWithoutRequest(Borrower $borrower): int
    {
        return DB::table('funding_applications')->insertGetId([
            // Well clear of the request ids, so a request can be given this id.
            'id' => 500,
            'application_number' => 'FUND-TEST-'.uniqid(),
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'funding_request_id' => null,
            'funding_sequence_id' => null,
            'status' => FundingApplication::DRAFT,
            'business_name' => 'Thornton Holdings LLC',
            'requested_amount' => 25000,
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => 'Term Loans',
            'preferred_funding_type' => 'Term Loan',
            'is_business_info_saved' => true,
            'is_funding_request_saved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function approvedRequestFor(int $borrowerId, ?int $id = null): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Credit Builder Cards',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('funding_requests')->insertGetId(array_filter([
            'id' => $id,
            'borrower_id' => $borrowerId,
            'partner_id' => $this->host->id,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($value) => $value !== null));
    }
}
