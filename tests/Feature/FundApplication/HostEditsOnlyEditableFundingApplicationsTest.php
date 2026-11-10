<?php

namespace Tests\Feature\FundApplication;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\PartnerFundApplication\PartnerFundingApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * One rule for everyone - host, white-label partner, certified partner and
 * borrower: a funding application is editable only while its status is in
 * config `funding_application.statuses.editable`.
 *
 * Defect (decision 2026-11-03): the host's team could edit any application at
 * any status, so the admin details page showed "Edit Funding Application" on a
 * Closed (Completed), Funded or Denied application. This replaces the
 * 2026-10-29 decision this test pinned before (HostEditsAnyFundingApplicationTest).
 *
 * The signature rule is unchanged: a real change to an application the
 * borrower has signed sends it back to be signed again, and the portal asks
 * before the edit (edit confirmation modal).
 */
#[CoversClass(PartnerFundingApplicationService::class)]
#[CoversClass(FundingApplication::class)]
class HostEditsOnlyEditableFundingApplicationsTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    private Partner $whiteLabel;

    private int $requestId;

    private int $applicationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->whiteLabel = $this->makeWhiteLabelPartner();
        $borrower = $this->makeBorrower($this->whiteLabel);

        $sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->requestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->whiteLabel->id,
            'funding_sequence_id' => $sequenceId,
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->applicationId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-0000016',
            'borrower_id' => $borrower->id,
            'partner_id' => $this->whiteLabel->id,
            'funding_request_id' => $this->requestId,
            'status' => FundingApplication::SUBMITTED,
            'monthly_revenue' => 42000,
            'business_checking_start_date' => '2020-01-01',
            'self_reported_personal_credit_score' => '700-749',
            'has_business_debt' => false,
            'signature_data' => json_encode(['signature_name' => 'Bo Rower', 'signed_at' => '2026-10-19T08:30:00+00:00']),
            'attestation_authorization' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setStatus(string $status): void
    {
        FundingApplication::whereKey($this->applicationId)->update(['status' => $status]);
    }

    private function saveFinancial(array $changes = [])
    {
        return $this->postJson('/api/v1/common/funding-application/financial-info', array_merge([
            'id' => $this->applicationId,
            'funding_request_id' => $this->requestId,
            'monthly_revenue' => 42000,
            'business_checking_start_date' => '2020-01-01',
            'self_reported_personal_credit_score' => '700-749',
            'has_business_debt' => false,
        ], $changes));
    }

    /** @return array<string, array{string}> */
    public static function lockedStatuses(): array
    {
        return [
            'Matched to Lender' => [FundingApplication::MATCHED_TO_LENDER],
            'Funded' => [FundingApplication::FUNDED],
            'Closed (Completed)' => [FundingApplication::CLOSED_COMPLETED],
            'Denied' => [FundingApplication::DENIED],
        ];
    }

    /** @return array<string, array{string}> */
    public static function signedEditableStatuses(): array
    {
        return [
            'Submitted' => [FundingApplication::SUBMITTED],
            'Approved (Final)' => [FundingApplication::APPROVED_FINAL],
        ];
    }

    #[Test]
    #[DataProvider('lockedStatuses')]
    public function the_host_cannot_edit_an_application_past_the_editable_statuses(string $status): void
    {
        $this->setStatus($status);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial(['monthly_revenue' => 55000])->assertStatus(423)
            ->assertJsonPath('message', __('application.funding_application_locked_staff', ['status' => $status]));

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertEquals(42000, $application->monthly_revenue);
        $this->assertSame($status, $application->status);
        $this->assertNotEmpty($application->signature_data);
        $this->assertTrue(ActivityLog::where('subject_id', $this->applicationId)
            ->where('event', 'Funding Application Edit Blocked')->exists());
    }

    #[Test]
    #[DataProvider('lockedStatuses')]
    public function the_host_is_told_a_locked_application_cannot_be_edited(string $status): void
    {
        $this->setStatus($status);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_request_id={$this->requestId}")
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.is_editable', false);

        $this->getJson("/api/v1/common/request-application/funding-application/{$this->applicationId}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', false);
    }

    #[Test]
    #[DataProvider('signedEditableStatuses')]
    public function the_host_edits_a_signed_application_at_an_editable_status_and_the_borrower_signs_again(string $status): void
    {
        $this->setStatus($status);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial(['monthly_revenue' => 55000])->assertOk();

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertEquals(55000, $application->monthly_revenue);
        // The borrower signed the old figures: they sign again.
        $this->assertSame(FundingApplication::resignTargetStatus(), $application->status);
        $this->assertTrue((bool) $application->requires_resign);
        $this->assertEmpty($application->signature_data);
    }

    #[Test]
    public function host_staff_may_edit_too(): void
    {
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->saveFinancial(['monthly_revenue' => 55000])->assertOk();
    }

    #[Test]
    public function host_staff_are_locked_out_too(): void
    {
        $this->setStatus(FundingApplication::FUNDED);
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->saveFinancial(['monthly_revenue' => 55000])->assertStatus(423);
    }

    #[Test]
    public function paging_through_without_a_change_keeps_the_status_and_the_signature(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial()->assertOk();

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertSame(FundingApplication::SUBMITTED, $application->status);
        $this->assertFalse((bool) $application->requires_resign);
        $this->assertNotEmpty($application->signature_data);
    }

    #[Test]
    public function a_draft_application_is_edited_without_a_resign(): void
    {
        $this->setStatus(FundingApplication::DRAFT);
        FundingApplication::whereKey($this->applicationId)->update(['signature_data' => null]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial(['monthly_revenue' => 55000])->assertOk();

        // Still being prepared (the wizard sets Draft / On Hold from how far it
        // is filled in), and nothing signed, so no re-sign.
        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertContains($application->status, FundingApplication::preSignatureStatuses());
        $this->assertFalse((bool) $application->requires_resign);
    }

    /**
     * Defect: saving a step of a Draft converted from the embed form (no
     * funding request, no federal compliance explanation) moved it to On Hold
     * with nothing changed - the wizard re-worked a pre-signature status out
     * from how far it is filled in (SCRUM-434 S434-BUG-04).
     */
    #[Test]
    public function saving_a_step_of_an_embed_form_draft_keeps_it_draft(): void
    {
        FundingApplication::whereKey($this->applicationId)->update([
            'status' => FundingApplication::DRAFT,
            'funding_request_id' => null,
            'signature_data' => null,
            'federal_compliance_disclosures_explanation' => null,
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial(['funding_request_id' => null])->assertOk();
        $this->assertSame(FundingApplication::DRAFT, FundingApplication::findOrFail($this->applicationId)->status);

        $this->saveFinancial(['funding_request_id' => null, 'monthly_revenue' => 51000])->assertOk();
        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertSame(FundingApplication::DRAFT, $application->status);
        $this->assertNull($application->funding_request_id);
        $this->assertEquals(51000, $application->monthly_revenue);
    }

    /**
     * Defect: the edit pop-up told the host an embed-form Draft "has already
     * been signed and submitted" and would need re-signing - the embed form
     * stores the borrower's consent signature, though the Draft was never
     * submitted and an edit costs no signature (SCRUM-434 S434-BUG-04).
     */
    #[Test]
    public function an_embed_form_draft_opens_for_editing_without_the_submitted_warning(): void
    {
        FundingApplication::whereKey($this->applicationId)->update([
            'status' => FundingApplication::DRAFT,
            'funding_request_id' => null,
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_application_id={$this->applicationId}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.edit_requires_resign', false)
            ->assertJsonPath('data.edit_needs_confirmation', false);
    }

    #[Test]
    public function a_draft_waiting_for_a_resign_still_asks_before_an_edit(): void
    {
        FundingApplication::whereKey($this->applicationId)->update([
            'status' => FundingApplication::DRAFT,
            'requires_resign' => true,
            'signature_data' => null,
            'previous_signature_data' => json_encode(['signature_name' => 'Bo Rower']),
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_application_id={$this->applicationId}")
            ->assertOk()
            ->assertJsonPath('data.edit_needs_confirmation', true);
    }

    #[Test]
    public function an_on_hold_application_still_moves_to_draft_once_federal_compliance_is_on_file(): void
    {
        FundingApplication::whereKey($this->applicationId)->update([
            'status' => FundingApplication::ON_HOLD,
            'signature_data' => null,
            'federal_compliance_disclosures_explanation' => 'None',
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial()->assertOk();

        $this->assertSame(FundingApplication::DRAFT, FundingApplication::findOrFail($this->applicationId)->status);
    }

    /** @return array<string, array{string}> */
    public static function preparationStatuses(): array
    {
        return [
            'Draft' => [FundingApplication::DRAFT],
            'On Hold' => [FundingApplication::ON_HOLD],
        ];
    }

    /**
     * Draft and On Hold applications are still being prepared: the partner
     * (its own borrower's) and the host (anyone's) both edit them, and with
     * nothing signed yet the edit costs no signature.
     */
    #[Test]
    #[DataProvider('preparationStatuses')]
    public function the_partner_and_the_host_both_edit_a_draft_or_on_hold_application(string $status): void
    {
        FundingApplication::whereKey($this->applicationId)->update(['status' => $status, 'signature_data' => null]);

        foreach ([$this->makePartnerAdmin($this->whiteLabel), $this->makePartnerAdmin($this->host)] as $i => $editor) {
            $this->actingAsUser($editor);

            $this->saveFinancial(['monthly_revenue' => 50000 + $i])->assertOk();

            $application = FundingApplication::findOrFail($this->applicationId);
            $this->assertEquals(50000 + $i, $application->monthly_revenue);
            $this->assertFalse((bool) $application->requires_resign);
        }
    }

    #[Test]
    public function the_host_is_told_the_form_is_open_and_an_edit_needs_confirmation(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_request_id={$this->requestId}")
            ->assertOk()
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.edit_needs_confirmation', true)
            ->assertJsonPath('data.edit_requires_resign', true);

        $this->getJson("/api/v1/common/request-application/funding-application/{$this->applicationId}")
            ->assertOk()
            ->assertJsonPath('data.is_editable', true);
    }

    #[Test]
    public function the_white_label_partner_is_locked_out_at_the_same_statuses(): void
    {
        $this->setStatus(FundingApplication::MATCHED_TO_LENDER);
        $this->actingAsUser($this->makePartnerAdmin($this->whiteLabel));

        $this->saveFinancial(['monthly_revenue' => 55000])->assertStatus(423);

        $this->getJson("/api/v1/common/funding-application/funding-request?funding_request_id={$this->requestId}")
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.is_editable', false);

        $this->assertSame(FundingApplication::MATCHED_TO_LENDER, FundingApplication::findOrFail($this->applicationId)->status);
    }

    #[Test]
    public function a_borrower_of_the_host_is_not_the_host_team(): void
    {
        $user = $this->makeBorrowerUser($this->host);

        $this->assertFalse(FundingApplication::isHostTeam($user));
        $this->assertTrue(FundingApplication::isHostTeam($this->makePartnerAdmin($this->host)));
        $this->assertFalse(FundingApplication::isHostTeam($this->makePartnerAdmin($this->whiteLabel)));
        $this->assertFalse(FundingApplication::isHostTeam(null));
    }
}
