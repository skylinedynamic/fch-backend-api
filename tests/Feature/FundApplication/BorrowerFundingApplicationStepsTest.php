<?php

namespace Tests\Feature\FundApplication;

use App\Http\Controllers\Api\V1\Borrower\FundApplication\FundingApplicationController;
use App\Models\Borrower\BorrowerDocType;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's funding application wizard:
 * /borrower/funding-application/{ownership-info, financial-info,
 * funding-request, disclosures-and-consent, federal-compliance-disclosures}
 * plus its documents. Each step saves onto the borrower's own application for
 * the chosen funding request. (Business info, attestation and the edit lock
 * are covered by FundingApplicationEditLockTest / FundingApplicationResignTest.)
 */
#[CoversClass(FundingApplicationController::class)]
class BorrowerFundingApplicationStepsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;
    private User $neighbour;
    private int $myRequestId;
    private int $myApplicationId;
    private int $theirApplicationId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->neighbour = $this->makeBorrowerUser($this->partner);

        $this->myRequestId = $this->fundingRequestFor($this->me);
        $this->myApplicationId = $this->applicationFor($this->me, $this->myRequestId);
        $this->theirApplicationId = $this->applicationFor($this->neighbour, $this->fundingRequestFor($this->neighbour), [
            'first_name' => 'Victim',
            'monthly_revenue' => 1000,
        ]);
    }

    private function fundingRequestFor(User $borrowerUser): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrowerUser->entity_id,
            'partner_id' => $borrowerUser->partner_id,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function applicationFor(User $borrowerUser, int $requestId, array $attributes = []): int
    {
        return DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrowerUser->entity_id,
            'partner_id' => $borrowerUser->partner_id,
            'funding_request_id' => $requestId,
            'status' => FundingApplication::ON_HOLD,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /** One valid payload per wizard step: [url, payload, flag the step sets]. */
    public static function steps(): array
    {
        return [
            'ownership' => ['ownership-info', [
                'first_name' => 'Bo', 'last_name' => 'Rower', 'date_of_birth' => '1985-04-12',
                'ssn' => '123456789', 'phone_number' => '(555) 123-4567', 'citizenship_status' => 'US Citizen',
                'street_address' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip_code' => '73301',
                'ownership' => 100,
            ], 'is_ownership_info_saved'],
            'financial' => ['financial-info', [
                'monthly_revenue' => 42000, 'business_checking_start_date' => '2020-01-01',
                'self_reported_personal_credit_score' => '700-749', 'has_business_debt' => false,
            ], 'is_financial_info_saved'],
            'funding request' => ['funding-request', [
                'requested_amount' => 50000, 'purpose_of_funds' => 'Inventory',
                'approved_funding' => 'Line of Credit', 'preferred_funding_type' => 'Line of Credit',
            ], 'is_funding_request_saved'],
            'disclosures' => ['disclosures-and-consent', [
                'credit_pull_authorization' => true, 'terms_conditions_acceptance' => true,
            ], 'is_disclosures_saved'],
            'federal compliance' => ['federal-compliance-disclosures', [
                'filed_for_bankruptcy' => false, 'any_pending_lawsuits' => false, 'has_federal_loan' => false,
                'has_federal_debt' => false, 'has_criminal_offense' => false, 'has_parole_or_probation' => false,
                'suspended_or_debarred' => false, 'any_outstanding_judgments_business' => false,
                'any_outstanding_judgments_owner' => false,
            ], 'is_federal_compliance_saved'],
        ];
    }

    private function url(string $step): string
    {
        return "/api/v1/borrower/funding-application/{$step}";
    }

    #[Test]
    public function the_wizard_is_for_signed_in_borrowers_only(): void
    {
        $this->getJson($this->url('financial-info'))->assertUnauthorized();

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->getJson($this->url('financial-info'))->assertForbidden();
    }

    #[Test]
    #[DataProvider('steps')]
    public function each_step_saves_onto_my_application_for_the_chosen_request(string $step, array $payload, string $flag): void
    {
        $this->actingAsUser($this->me);

        $this->postJson($this->url($step), array_merge($payload, [
            'id' => $this->myApplicationId,
            'funding_request_id' => $this->myRequestId,
        ]))->assertOk();

        $application = FundingApplication::findOrFail($this->myApplicationId);
        $this->assertTrue((bool) $application->{$flag});
        $this->assertSame($this->me->entity_id, $application->borrower_id);
    }

    #[Test]
    #[DataProvider('steps')]
    public function each_step_reads_back_my_application(string $step): void
    {
        $this->actingAsUser($this->me);

        $this->getJson($this->url($step) . '?funding_request_id=' . $this->myRequestId)->assertOk();
    }

    #[Test]
    #[DataProvider('steps')]
    public function each_step_validates_its_fields(string $step): void
    {
        $this->actingAsUser($this->me);

        $this->postJson($this->url($step), ['id' => $this->myApplicationId])->assertStatus(422);
    }

    /**
     * Defect: the step requests checked only that the id existed, and the
     * service then loaded it with a bare find() and saved onto it with the
     * caller's borrower_id - so a borrower could overwrite another borrower's
     * application and take it over.
     */
    #[Test]
    #[DataProvider('steps')]
    public function no_step_can_save_onto_another_borrowers_application(string $step, array $payload): void
    {
        $this->actingAsUser($this->me);

        $this->postJson($this->url($step), array_merge($payload, ['id' => $this->theirApplicationId]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);

        $theirs = FundingApplication::findOrFail($this->theirApplicationId);
        $this->assertSame($this->neighbour->entity_id, $theirs->borrower_id);
        $this->assertSame('Victim', $theirs->first_name);
    }

    #[Test]
    public function a_step_cannot_be_filed_against_another_borrowers_funding_request(): void
    {
        $theirRequest = DB::table('funding_applications')->where('id', $this->theirApplicationId)->value('funding_request_id');
        $this->actingAsUser($this->me);

        $this->postJson($this->url('funding-request'), [
            'requested_amount' => 50000, 'purpose_of_funds' => 'Inventory',
            'approved_funding' => 'Line of Credit', 'preferred_funding_type' => 'Line of Credit',
            'funding_request_id' => $theirRequest,
        ])->assertStatus(422)->assertJsonValidationErrors(['funding_request_id']);
    }

    #[Test]
    public function co_borrowers_are_saved_with_my_ownership_step(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson($this->url('ownership-info'), array_merge(self::steps()['ownership'][1], [
            'id' => $this->myApplicationId,
            'has_co_borrower' => true,
            'co_borrowers' => [['first_name' => 'Co', 'last_name' => 'Owner', 'ownership' => 40]],
        ]))->assertOk();

        $this->assertSame(1, FundingApplicationCoBorrower::where('funding_application_id', $this->myApplicationId)->count());
    }

    /**
     * Defect: co-borrowers were upserted by the id the request sent, so naming
     * another application's co-borrower rewrote it - SSN and licence fields
     * included - and moved it onto the caller's application.
     */
    #[Test]
    public function another_applications_co_borrower_cannot_be_rewritten(): void
    {
        $theirCoBorrower = FundingApplicationCoBorrower::create([
            'funding_application_id' => $this->theirApplicationId,
            'first_name' => 'Their',
            'last_name' => 'Partner',
        ]);
        $this->actingAsUser($this->me);

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
    public function i_upload_list_download_and_delete_documents_on_my_application(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $this->actingAsUser($this->me);

        $this->postJson($this->url('documents/upload'), [
            'funding_application_id' => $this->myApplicationId,
            'document_type_id' => $type->id,
            'files' => [UploadedFile::fake()->create('march.pdf', 10, 'application/pdf')],
        ])->assertSuccessful();

        $documentId = DB::table('funding_application_documents')->where('funding_application_id', $this->myApplicationId)->value('id');
        $this->assertNotNull($documentId);

        $this->getJson($this->url("documents/{$this->myApplicationId}"))->assertOk();
        $this->deleteJson($this->url("documents/{$documentId}"))->assertOk();
        $this->assertDatabaseMissing('funding_application_documents', ['id' => $documentId]);
    }

    /**
     * Download reads the file through the public disk (findings O16 - it used
     * to read storage_path('app/public/...') directly), so it is put there.
     */
    #[Test]
    public function i_download_my_document_under_its_original_name(): void
    {
        // storage_path() is pointed at a throwaway folder: the real one holds
        // the developer's own files under these same low ids.
        $storage = sys_get_temp_dir() . '/fch-storage-' . uniqid();
        foreach (['app/public', 'framework/views', 'framework/cache/data', 'framework/sessions', 'logs'] as $dir) {
            File::ensureDirectoryExists("{$storage}/{$dir}");
        }
        foreach (glob(storage_path('oauth-*.key')) as $key) {
            File::copy($key, $storage . '/' . basename($key));
        }
        $this->app->useStoragePath($storage);
        $relative = "funding-application/{$this->me->entity_id}/FA-{$this->myApplicationId}/statement-" . uniqid() . '.pdf';
        \Illuminate\Support\Facades\Storage::disk('public')->put($relative, '%PDF-1.4 test');
        $documentId = DB::table('funding_application_documents')->insertGetId([
            'funding_application_id' => $this->myApplicationId,
            'borrower_id' => $this->me->entity_id,
            'path' => $relative,
            'original_name' => 'March statement.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->me);

        try {
            $response = $this->get($this->url("documents/{$documentId}/download"))->assertOk();
            $this->assertStringContainsString('March statement.pdf', $response->headers->get('Content-Disposition'));
        } finally {
            File::deleteDirectory($storage);
        }
    }

    /**
     * Defect: the upload request checked only that the application existed,
     * so a borrower could file documents onto someone else's application.
     */
    #[Test]
    public function documents_cannot_be_filed_onto_another_borrowers_application(): void
    {
        $type = BorrowerDocType::create(['name' => 'Bank Statement']);
        $this->actingAsUser($this->me);

        $this->postJson($this->url('documents/upload'), [
            'funding_application_id' => $this->theirApplicationId,
            'document_type_id' => $type->id,
            'files' => [UploadedFile::fake()->create('planted.pdf', 10, 'application/pdf')],
        ])->assertStatus(422)->assertJsonValidationErrors(['funding_application_id']);

        $this->assertDatabaseMissing('funding_application_documents', ['funding_application_id' => $this->theirApplicationId]);
    }

    #[Test]
    public function another_borrowers_documents_cannot_be_downloaded_or_deleted(): void
    {
        $documentId = DB::table('funding_application_documents')->insertGetId([
            'funding_application_id' => $this->theirApplicationId,
            'borrower_id' => $this->neighbour->entity_id,
            'path' => 'funding-application/x/statement.pdf',
            'original_name' => 'statement.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->me);

        $this->getJson($this->url("documents/{$documentId}/download"))->assertForbidden();
        $this->deleteJson($this->url("documents/{$documentId}"))->assertStatus(500);
        $this->assertDatabaseHas('funding_application_documents', ['id' => $documentId]);
    }
}
