<?php

namespace Tests\Feature\FundApplication;

use App\Http\Controllers\Api\V1\FCH\Common\PartnerFundApplication\PartnerFundingApplicationController;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal preparing a funding application for one of its borrowers:
 * /common/funding-application/{ownership-info, financial-info, funding-request,
 * disclosures-and-consent, federal-compliance-disclosures} plus documents.
 * A partner works on its own borrowers' applications; the host's team on any -
 * the rule of Borrower::scopeVisibleTo().
 *
 * Defect: none of it was scoped. Reads took borrower_id / funding_request_id
 * from the query string, saves took any application id and re-stamped it with
 * the caller's partner, and documents were listed, uploaded, downloaded and
 * deleted by id alone - so any partner could read or rewrite any borrower's
 * application (SSN, financials, ownership) and pull it into its own book.
 */
#[CoversClass(PartnerFundingApplicationController::class)]
class PartnerFundingApplicationStepsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;
    private int $myRequestId;
    private int $myApplicationId;
    private int $theirRequestId;
    private int $theirApplicationId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);

        $mine = $this->makeBorrower($this->partner);
        $theirs = $this->makeBorrower($this->otherPartner);
        $this->myRequestId = $this->fundingRequestFor($mine->id, $this->partner->id);
        $this->myApplicationId = $this->applicationFor($mine->id, $this->partner->id, $this->myRequestId);
        $this->theirRequestId = $this->fundingRequestFor($theirs->id, $this->otherPartner->id);
        $this->theirApplicationId = $this->applicationFor($theirs->id, $this->otherPartner->id, $this->theirRequestId, [
            'first_name' => 'Victim',
            'ssn' => '987654321',
        ]);
    }

    private function fundingRequestFor(int $borrowerId, int $partnerId): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function applicationFor(int $borrowerId, int $partnerId, int $requestId, array $attributes = []): int
    {
        return DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'funding_request_id' => $requestId,
            'status' => FundingApplication::ON_HOLD,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public static function steps(): array
    {
        return BorrowerFundingApplicationStepsTest::steps();
    }

    private function url(string $step): string
    {
        return "/api/v1/common/funding-application/{$step}";
    }

    #[Test]
    #[DataProvider('steps')]
    public function a_partner_prepares_each_step_for_its_own_borrower(string $step, array $payload, string $flag): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url($step), array_merge($payload, [
            'id' => $this->myApplicationId,
            'funding_request_id' => $this->myRequestId,
        ]))->assertOk();

        $application = FundingApplication::findOrFail($this->myApplicationId);
        $this->assertTrue((bool) $application->{$flag});
        $this->assertTrue((bool) $application->is_partner_prepared);
    }

    #[Test]
    #[DataProvider('steps')]
    public function a_partner_reads_each_step_of_its_own_borrowers_application(string $step): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url($step) . '?funding_request_id=' . $this->myRequestId)->assertOk();
    }

    #[Test]
    #[DataProvider('steps')]
    public function a_partner_cannot_read_another_partners_borrowers_application(string $step): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url($step) . '?funding_request_id=' . $this->theirRequestId)
            ->assertNotFound()
            ->assertJsonMissingPath('data.ssn');
    }

    #[Test]
    #[DataProvider('steps')]
    public function a_partner_cannot_save_onto_another_partners_borrowers_application(string $step, array $payload): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url($step), array_merge($payload, ['id' => $this->theirApplicationId]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);

        $theirs = FundingApplication::findOrFail($this->theirApplicationId);
        $this->assertSame($this->otherPartner->id, $theirs->partner_id);
        $this->assertSame('Victim', $theirs->first_name);
    }

    #[Test]
    public function a_partner_cannot_start_an_application_on_another_partners_funding_request(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('funding-request'), array_merge(self::steps()['funding request'][1], [
            'funding_request_id' => $this->theirRequestId,
        ]))->assertStatus(422)->assertJsonValidationErrors(['funding_request_id']);
    }

    #[Test]
    public function the_hosts_team_works_on_any_partners_borrowers_application(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->getJson($this->url('financial-info') . '?funding_request_id=' . $this->theirRequestId)->assertOk();
        $this->postJson($this->url('financial-info'), array_merge(self::steps()['financial'][1], [
            'id' => $this->theirApplicationId,
        ]))->assertOk();
    }

    #[Test]
    public function another_applications_co_borrower_cannot_be_rewritten(): void
    {
        $theirCoBorrower = FundingApplicationCoBorrower::create([
            'funding_application_id' => $this->theirApplicationId,
            'first_name' => 'Their',
            'last_name' => 'Partner',
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('ownership-info'), array_merge(self::steps()['ownership'][1], [
            'id' => $this->myApplicationId,
            'has_co_borrower' => true,
            'co_borrowers' => [['id' => $theirCoBorrower->id, 'first_name' => 'Hijacked', 'last_name' => 'X']],
        ]))->assertOk();

        $theirCoBorrower->refresh();
        $this->assertSame($this->theirApplicationId, $theirCoBorrower->funding_application_id);
        $this->assertSame('Their', $theirCoBorrower->first_name);
    }

    #[Test]
    public function a_partner_files_and_lists_documents_on_its_own_borrowers_application(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('documents/upload'), [
            'funding_application_id' => $this->myApplicationId,
            'document_type_id' => $type->id,
            'files' => [UploadedFile::fake()->create('march.pdf', 10, 'application/pdf')],
        ])->assertSuccessful();

        $this->getJson($this->url("documents/{$this->myApplicationId}"))->assertOk();
        $this->assertDatabaseHas('funding_application_documents', ['funding_application_id' => $this->myApplicationId]);
    }

    #[Test]
    public function another_partners_application_documents_are_out_of_reach(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $documentId = DB::table('funding_application_documents')->insertGetId([
            'funding_application_id' => $this->theirApplicationId,
            'borrower_id' => FundingApplication::find($this->theirApplicationId)->borrower_id,
            'path' => 'funding-application/x/statement.pdf',
            'original_name' => 'statement.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('documents/upload'), [
            'funding_application_id' => $this->theirApplicationId,
            'document_type_id' => $type->id,
            'files' => [UploadedFile::fake()->create('planted.pdf', 10, 'application/pdf')],
        ])->assertStatus(422);
        $this->getJson($this->url("documents/{$this->theirApplicationId}"))->assertNotFound();
        $this->getJson($this->url("documents/{$documentId}/download"))->assertNotFound();
        $this->deleteJson($this->url("documents/{$documentId}"))->assertNotFound();

        $this->assertDatabaseHas('funding_application_documents', ['id' => $documentId]);
        $this->assertSame(1, DB::table('funding_application_documents')->where('funding_application_id', $this->theirApplicationId)->count());
    }
}
