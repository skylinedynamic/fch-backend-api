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
use App\Rules\DateOfBirth;
use Tests\Support\InteractsWithPortalUsers;
use Tests\Support\CreatesFundingTypes;
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
    use CreatesFundingTypes;
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
        $this->createFundingTypes();

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

    /**
     * The host's admin may edit any partner's borrower's application - a
     * White-Label's and a Certified partner's alike - from /fund-application/{id}.
     */
    #[Test]
    #[DataProvider('steps')]
    public function the_host_admin_edits_each_step_of_any_partners_borrowers_application(string $step, array $payload, string $flag): void
    {
        $whiteLabel = $this->makeWhiteLabelPartner();
        $wlBorrower = $this->makeBorrower($whiteLabel);
        $wlRequestId = $this->fundingRequestFor($wlBorrower->id, $whiteLabel->id);
        $wlApplicationId = $this->applicationFor($wlBorrower->id, $whiteLabel->id, $wlRequestId);

        $this->actingAsUser($this->makePartnerAdmin($this->host));

        foreach ([[$this->theirApplicationId, $this->theirRequestId], [$wlApplicationId, $wlRequestId]] as [$applicationId, $requestId]) {
            // The admin wizard reads with the request id and saves with both ids.
            $this->getJson($this->url($step) . "?funding_request_id={$requestId}")
                ->assertOk()
                ->assertJsonPath('data.id', $applicationId);

            $this->postJson($this->url($step), array_merge($payload, [
                'id' => $applicationId,
                'funding_request_id' => $requestId,
            ]))->assertOk();

            $this->assertTrue((bool) FundingApplication::findOrFail($applicationId)->{$flag}, "{$step} for application {$applicationId}");
        }
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

    /** BUG-158: prefilled with the full SSN on file, never its last four digits. */
    #[Test]
    public function the_ownership_step_prefills_the_full_ssn_on_file_not_its_last_four(): void
    {
        $borrowerId = DB::table('funding_applications')->where('id', $this->myApplicationId)->value('borrower_id');
        $borrower = \App\Models\Borrower\Borrower::findOrFail($borrowerId);
        $borrower->ssn = '123-45-6789';
        $borrower->save();
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url('ownership-info') . '?funding_request_id=' . $this->myRequestId)
            ->assertOk()
            ->assertJsonPath('data.ssn', '123456789');
    }

    #[Test]
    public function a_profile_holding_only_the_last_four_prefills_no_ssn(): void
    {
        $borrowerId = DB::table('funding_applications')->where('id', $this->myApplicationId)->value('borrower_id');
        DB::table('borrowers')->where('id', $borrowerId)
            ->update(['ssn_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('6789')]);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url('ownership-info') . '?funding_request_id=' . $this->myRequestId)
            ->assertOk()
            ->assertJsonPath('data.ssn', null);
    }

    #[Test]
    public function a_partner_saves_a_written_ssn_as_its_nine_digits(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('ownership-info'), array_merge(self::steps()['ownership'][1], [
            'id' => $this->myApplicationId,
            'funding_request_id' => $this->myRequestId,
            'ssn' => '123-45-6789',
            // Owner and co-borrowers together may hold at most 100% (BUG-108).
            'ownership' => 60,
            'has_co_borrower' => true,
            'co_borrowers' => [['first_name' => 'Co', 'last_name' => 'Owner', 'ownership' => 40, 'ssn' => '234-56-7890']],
        ]))->assertOk();

        $this->assertSame('123456789', DB::table('funding_applications')->where('id', $this->myApplicationId)->value('ssn'));
        $this->assertSame('234567890', FundingApplicationCoBorrower::where('funding_application_id', $this->myApplicationId)->firstOrFail()->ssn);
    }

    /** Defect: R2 BUG-033, on the partner's form too: a save from an older copy is refused. */
    #[Test]
    public function a_partners_save_from_an_older_copy_is_refused(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        DB::table('funding_applications')->where('id', $this->myApplicationId)->update(['updated_at' => now()]);
        $this->actingAsUser($this->partnerAdmin);
        $step = array_merge(self::steps()['funding request'][1], ['id' => $this->myApplicationId, 'funding_request_id' => $this->myRequestId]);

        $this->travelTo('2026-10-21 10:01:00');
        $this->postJson($this->url('funding-request'), $step + ['timeframe_needed' => '1 Month', 'expected_version' => '2026-10-21 10:00:00'])->assertOk();

        $this->postJson($this->url('funding-request'), $step + ['timeframe_needed' => '1-2 Weeks', 'expected_version' => '2026-10-21 10:00:00'])
            ->assertStatus(409);
        $this->assertSame('1 Month', FundingApplication::find($this->myApplicationId)->timeframe_needed);
    }

    /** Defect: BUG-108, on the partner's form too. */
    #[Test]
    public function a_partner_cannot_save_more_than_100_percent_ownership(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('ownership-info'), array_merge(self::steps()['ownership'][1], [
            'id' => $this->myApplicationId,
            'funding_request_id' => $this->myRequestId,
            'ownership' => 100,
            'has_co_borrower' => true,
            'co_borrowers' => [['first_name' => 'Co', 'last_name' => 'Owner', 'ownership' => 50]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('ownership');
    }

    #[Test]
    public function a_partners_ownership_step_checks_the_owner_and_co_borrower_formats(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('ownership-info'), array_merge(self::steps()['ownership'][1], [
            'id' => $this->myApplicationId,
            'funding_request_id' => $this->myRequestId,
            'ssn' => '666-12-3456', 'phone_number' => '(124) 982-7457', 'zip_code' => '7330',
            'has_co_borrower' => true,
            'co_borrowers' => [['first_name' => 'Co', 'last_name' => 'Owner', 'ssn' => 'abc', 'zip_code' => '1', 'mobile_phone' => '1234567890']],
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'ssn' => __('common.ssn_invalid'),
            'phone_number' => __('embed_form.phone_invalid'),
            'zip_code' => __('common.postal_code_invalid'),
            'co_borrowers.0.ssn' => __('common.ssn_invalid'),
            'co_borrowers.0.zip_code' => __('common.postal_code_invalid'),
            'co_borrowers.0.mobile_phone' => __('embed_form.phone_invalid'),
        ]);
    }


    /**
     * The document step opens the borrower's file folder (borrowers/{id}). The
     * partner portal had no borrower id here and used the signed-in user's own
     * entity id instead - a partner's or employee's id - which the file manager
     * now refuses (findings D2). The funding request names its borrower.
     */
    #[Test]
    public function the_funding_request_names_its_borrower(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $borrowerId = FundingApplication::findOrFail($this->myApplicationId)->borrower_id;

        $this->getJson($this->url('funding-request') . '?funding_request_id=' . $this->myRequestId)
            ->assertOk()
            ->assertJsonPath('data.borrower_id', $borrowerId);
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

    /**
     * Defect (findings O17): every save re-stamped the application with the
     * caller's partner, so the host's team preparing a partner's borrower's
     * application moved it into the host's book - the partner lost its
     * notifications and dashboard count. The application belongs to the
     * borrower's partner whoever prepares it; is_partner_prepared says that a
     * partner-side user did.
     */
    #[Test]
    #[DataProvider('steps')]
    public function a_save_by_the_hosts_team_leaves_the_application_with_the_borrowers_partner(string $step, array $payload): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->postJson($this->url($step), array_merge($payload, [
            'id' => $this->theirApplicationId,
            'funding_request_id' => $this->theirRequestId,
        ]))->assertOk();

        $application = FundingApplication::findOrFail($this->theirApplicationId);
        $this->assertSame($this->otherPartner->id, $application->partner_id);
        $this->assertTrue((bool) $application->is_partner_prepared);
    }

    #[Test]
    public function an_application_the_hosts_team_starts_belongs_to_the_borrowers_partner(): void
    {
        $borrower = $this->makeBorrower($this->otherPartner);
        $requestId = $this->fundingRequestFor($borrower->id, $this->otherPartner->id);
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->postJson($this->url('funding-request'), array_merge(self::steps()['funding request'][1], [
            'funding_request_id' => $requestId,
        ]))->assertOk();

        $application = FundingApplication::where('borrower_id', $borrower->id)->firstOrFail();
        $this->assertSame($this->otherPartner->id, $application->partner_id);
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

    /**
     * Defect: on the admin/partner wizard the owner's date of birth was only
     * `date`, and a co-borrower saved with the step was not checked at all.
     */
    #[Test]
    public function on_the_partner_wizard_the_owner_and_each_co_borrower_must_be_at_least_the_minimum_age(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $underAge = DateOfBirth::latestAllowedDate()->addDay()->toDateString();
        $turning18 = DateOfBirth::latestAllowedDate()->toDateString();
        $minimumAge = __('common.date_of_birth_minimum_age', ['age' => 18]);
        $step = fn (string $dob, string $coDob) => array_merge(self::steps()['ownership'][1], [
            'id' => $this->myApplicationId,
            'funding_request_id' => $this->myRequestId,
            'ownership' => 60,
            'date_of_birth' => $dob,
            'has_co_borrower' => true,
            'co_borrowers' => [['first_name' => 'Co', 'last_name' => 'Owner', 'ownership' => 40, 'date_of_birth' => $coDob]],
        ]);

        $this->postJson($this->url('ownership-info'), $step($underAge, $underAge))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth' => $minimumAge, 'co_borrowers.0.date_of_birth' => $minimumAge]);

        $this->postJson($this->url('ownership-info'), $step(now()->addYear()->toDateString(), '2000-02-30'))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'date_of_birth' => __('common.date_of_birth_future'),
                'co_borrowers.0.date_of_birth' => __('common.date_of_birth_invalid'),
            ]);

        $this->postJson($this->url('ownership-info'), $step($turning18, $turning18))->assertOk();
        $this->assertSame($turning18, toDateOnly(FundingApplicationCoBorrower::where('funding_application_id', $this->myApplicationId)->firstOrFail()->date_of_birth));
    }

    #[Test]
    public function the_partner_co_borrower_check_refuses_an_under_age_co_borrower(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('co-borrower-validation'), [
            'date_of_birth' => DateOfBirth::latestAllowedDate()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'date_of_birth' => __('common.date_of_birth_minimum_age', ['age' => 18]),
        ]);

        $this->postJson($this->url('co-borrower-validation'), [
            'date_of_birth' => DateOfBirth::latestAllowedDate()->toDateString(),
        ])->assertJsonMissingValidationErrors(['date_of_birth']);
    }
}
