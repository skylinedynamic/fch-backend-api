<?php

namespace Tests\Feature\FundApplication;

use App\Http\Resources\Borrower\FundApplication\FundingRequestResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ConvertsEmbedFormSubmissions;
use Tests\Support\InteractsWithPortalUsers;
use Tests\Support\CreatesFundingTypes;
use Tests\TestCase;

/**
 * Defect: a borrower could not open their own funding application for editing
 * when it had no funding request (converted from the embed form): the wizard
 * read the application by funding_request_id only, and the step resource put
 * the borrower's latest approved funding request on it - which the wizard then
 * saved back, attaching a request the application never had.
 */
#[CoversClass(FundingApplicationService::class)]
#[CoversClass(FundingRequestResource::class)]
class BorrowerEditsApplicationWithoutFundingRequestTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;
    use ConvertsEmbedFormSubmissions;
    use CreatesFundingTypes;

    private Partner $partner;

    private User $user;

    private int $applicationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes();

        Storage::fake('public');
        Mail::fake();
        Notification::fake();

        $this->partner = $this->makeHostPartner();
        $this->user = $this->makeBorrowerUser($this->partner);
        $this->applicationId = $this->applicationWithoutRequest(Borrower::findOrFail($this->user->entity_id));
        // Converted from the embed form, as such an application is.
        $this->markConvertedFromEmbedForm($this->applicationId);
    }

    #[Test]
    public function a_borrower_reads_their_application_without_a_funding_request_by_its_id(): void
    {
        // An approved request of their own that the application is not for.
        $this->approvedRequestFor($this->user->entity_id);
        $this->actingAsUser($this->user);

        foreach (['business-info', 'ownership-info', 'financial-info', 'funding-request', 'disclosures-and-consent', 'federal-compliance-disclosures'] as $step) {
            $this->getJson("/api/v1/borrower/funding-application/{$step}?funding_application_id={$this->applicationId}")
                ->assertOk()
                ->assertJsonPath('data.id', $this->applicationId);
        }

        $this->getJson("/api/v1/borrower/funding-application/funding-request?funding_application_id={$this->applicationId}")
            ->assertJsonPath('data.funding_request_id', null)
            ->assertJsonPath('data.funding_sequence_id', null);
    }

    #[Test]
    public function a_borrower_cannot_read_another_borrowers_application_by_its_id(): void
    {
        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->getJson("/api/v1/borrower/funding-application/business-info?funding_application_id={$this->applicationId}")
            ->assertNotFound();
    }

    #[Test]
    public function saving_a_step_keeps_the_application_without_a_funding_request(): void
    {
        $this->approvedRequestFor($this->user->entity_id);
        // Without a funding request, the step chooses the funding sequence
        // (FundingSequenceOnFundingRequestStepTest).
        $termLoans = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Term Loans',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'funding_type_id' => DB::table('lender_product_categories')->where('name', 'Term Loan')->value('id'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->user);

        $this->postJson('/api/v1/borrower/funding-application/funding-request', [
            'id' => $this->applicationId,
            'requested_amount' => 40000,
            'purpose_of_funds' => 'Working Capital',
            'funding_sequence_id' => $termLoans,
            'preferred_funding_type' => 'Term Loan',
        ])->assertOk();

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertSame(40000.0, (float) $application->requested_amount);
        $this->assertNull($application->funding_request_id);
        $this->assertSame($termLoans, (int) $application->funding_sequence_id);
    }

    private function applicationWithoutRequest(Borrower $borrower): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-' . uniqid(),
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

    private function approvedRequestFor(int $borrowerId): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Credit Builder Cards',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'partner_id' => $this->partner->id,
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
