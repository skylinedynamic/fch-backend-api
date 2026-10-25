<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host's team may edit any existing funding application - any partner's
 * borrower, at any status - from /fund-application/{id}. White-Label and
 * Certified partners keep the lock: past Approved (Final) they cannot edit.
 *
 * The signature rule still applies to the host: a real change to an
 * application the borrower has signed puts it back to Draft and the borrower
 * signs again, and the portal asks before the edit (edit confirmation modal).
 *
 * Before, the host got 423 "This application can no longer be edited" on
 * Matched to Lender, Denied and every status after Approved (Final).
 */
class HostEditsAnyFundingApplicationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

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
            'status' => FundingApplication::MATCHED_TO_LENDER,
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

    #[Test]
    #[DataProvider('lockedStatuses')]
    public function the_host_edits_a_white_label_borrowers_application_at_any_status_and_the_borrower_signs_again(string $status): void
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
    public function paging_through_without_a_change_keeps_the_status_and_the_signature(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->saveFinancial()->assertOk();

        $application = FundingApplication::findOrFail($this->applicationId);
        $this->assertSame(FundingApplication::MATCHED_TO_LENDER, $application->status);
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
    public function the_white_label_partner_itself_is_still_locked_out(): void
    {
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
