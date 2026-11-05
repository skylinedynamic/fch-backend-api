<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\Support\CreatesFundingTypes;
use Tests\TestCase;

/**
 * Defect: BUG-082. A borrower who opened /fund-application/{id} with another
 * borrower's funding request id got the wizard with that request's funding
 * option name, and Save & Next would have created an application for them
 * attached to the other borrower's request: the wizard loaded the request by
 * id without checking whose it was.
 */
#[CoversClass(FundingApplicationService::class)]
class FundingApplicationForeignRequestTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFundingTypes;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private int $othersRequestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();

        $this->partner = $this->makeHostPartner();
        $michael = $this->makeBorrower($this->partner, ['first_name' => 'Michael', 'last_name' => 'Chen']);
        $this->othersRequestId = $this->approvedRequestFor($michael, 'Business Credit Builder Cards');
    }

    #[Test]
    public function a_borrower_cannot_read_the_wizard_for_another_borrowers_request(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        foreach (['business-info', 'ownership-info', 'financial-info', 'funding-request'] as $step) {
            $response = $this->getJson("/api/v1/borrower/funding-application/{$step}?funding_request_id={$this->othersRequestId}");

            $response->assertNotFound();
            $this->assertStringNotContainsString('Business Credit Builder Cards', $response->getContent(), $step);
        }
    }

    #[Test]
    public function a_borrower_cannot_save_an_application_against_another_borrowers_request(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        // The validated funding_request_id is scoped to the borrower, but the
        // service also reads the camel-case fundingRequestId the wizard sends,
        // which no rule looks at.
        $this->postJson('/api/v1/borrower/funding-application/funding-request', [
            'fundingRequestId' => $this->othersRequestId,
            'requested_amount' => 50000,
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => 'Business Credit Builder Cards',
            'preferred_funding_type' => 'Term Loan',
        ])->assertNotFound();

        $this->assertSame(0, FundingApplication::where('funding_request_id', $this->othersRequestId)->count());
    }

    #[Test]
    public function a_borrower_still_opens_the_wizard_for_their_own_request(): void
    {
        $user = $this->makeBorrowerUser($this->partner);
        $ownRequestId = $this->approvedRequestFor(Borrower::findOrFail($user->entity_id), 'Term Loans');
        $this->actingAsUser($user);

        $this->getJson("/api/v1/borrower/funding-application/funding-request?funding_request_id={$ownRequestId}")
            ->assertOk()
            ->assertJsonPath('data.approved_funding', 'Term Loans');
    }

    #[Test]
    public function a_partner_cannot_attach_its_borrowers_application_to_another_borrowers_request(): void
    {
        $wanda = $this->makeWhiteLabelPartner();
        $wandaClient = $this->makeBorrower($wanda);
        $ownApplicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-' . uniqid(),
            'borrower_id' => $wandaClient->id,
            'partner_id' => $wanda->id,
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->makePartnerAdmin($wanda));

        // Wanda's own client's application, but Michael Chen's request.
        $this->postJson('/api/v1/common/funding-application/funding-request', [
            'id' => $ownApplicationId,
            'fundingRequestId' => $this->othersRequestId,
            'requested_amount' => 50000,
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => 'Business Credit Builder Cards',
            'preferred_funding_type' => 'Term Loan',
        ])->assertNotFound();

        $this->assertSame(0, FundingApplication::where('funding_request_id', $this->othersRequestId)->count());
    }

    private function approvedRequestFor(Borrower $borrower, string $sequenceName): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => $sequenceName,
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Term Loan',
            'requested_amount' => 30000,
            'use_of_funds' => 'Working Capital',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
