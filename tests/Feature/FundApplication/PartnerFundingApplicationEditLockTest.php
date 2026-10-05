<?php

namespace Tests\Feature\FundApplication;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A partner preparing an application on a borrower's behalf is under the same
 * rules as the borrower, with one addition that matters more: it is the
 * *borrower's* signature a partner's edit invalidates. The borrower attested to
 * specific figures; if a partner changes them, that attestation no longer covers
 * what is on file, so the borrower has to sign again.
 *
 * PartnerFundingApplicationResignTest covers the Pending Review case. This
 * covers the lock, the statuses past Pending, and the audit trail - which has to
 * record that it was the partner, not the borrower, who made the change.
 */
class PartnerFundingApplicationEditLockTest extends TestCase
{
    use RefreshDatabase;

    private int $borrowerId;
    private int $partnerId;
    private int $fundingRequestId;
    private int $applicationId;

    private array $businessInfoPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-' . uniqid() . '@example.com',
            'phone_number' => '(111) 111-1111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'business_name' => 'Alpha Tech Solutions',
            'email' => 'borrower-' . uniqid() . '@example.com',
            'phone_number' => '(546) 456-4520',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fundingSequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fundingRequestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'funding_sequence_id' => $fundingSequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->businessInfoPayload = [
            'id' => null,
            'funding_request_id' => $this->fundingRequestId,
            'full_name' => 'Michael Chen',
            'business_name' => 'Alpha Tech Solutions',
            'business_email' => 'contact@alphatech.example.com',
            'business_phone_no' => '(546) 456-4520',
            'business_country' => 'USA',
            'business_country_code' => 'US',
            'business_address' => '500 Innovation Dr',
            'business_city' => 'Vancouver',
            'business_state' => 'Washington',
            'business_postal_code' => '94107',
            'industry_type' => 'Real Estate (Non-Lending)',
            'entity_type' => 'Sole Proprietor',
            'years_in_business' => '7 Years 1 Month 3 Days',
            'number_of_employees' => null,
        ];

        $this->applicationId = DB::table('funding_applications')->insertGetId(array_merge(
            array_diff_key($this->businessInfoPayload, ['id' => null]),
            [
                'application_number' => 'FUND-TEST-PARTNER-LOCK',
                'borrower_id' => $this->borrowerId,
                'partner_id' => $this->partnerId,
                'is_partner_prepared' => true,
                'status' => FundingApplication::PENDING,
                'signature_data' => json_encode(['signature_name' => 'Michael Chen', 'signed_at' => now()->toDateTimeString()]),
                'attestation_authorization' => true,
                'requires_resign' => false,
                'is_business_info_saved' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));
        $this->businessInfoPayload['id'] = $this->applicationId;

        $userId = DB::table('users')->insertGetId([
            'name' => 'Pat Partner',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => \App\Models\Partner\Partner::class,
            'entity_id' => $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    private function setStatus(string $status): void
    {
        DB::table('funding_applications')->where('id', $this->applicationId)->update(['status' => $status]);
    }

    private function postBusinessInfo(array $overrides = [])
    {
        return $this->postJson(
            '/api/v1/common/funding-application/business-info',
            array_merge($this->businessInfoPayload, $overrides)
        );
    }

    private function logsFor(string $event)
    {
        return ActivityLog::where('subject_type', FundingApplication::class)
            ->where('subject_id', $this->applicationId)
            ->where('event', $event)
            ->get();
    }

    // ── The lock applies to partners too ─────────────────────────────

    #[Test]
    public function a_partner_cannot_edit_an_application_that_is_with_a_lender(): void
    {
        $this->setStatus(FundingApplication::LENDER_REVIEWING);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])
            ->assertStatus(423);

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame('Alpha Tech Solutions', $application->business_name);
        $this->assertSame(FundingApplication::LENDER_REVIEWING, $application->status);
    }

    #[Test]
    public function a_partner_cannot_edit_a_funded_application(): void
    {
        $this->setStatus(FundingApplication::FUNDED);

        $this->postBusinessInfo(['business_city' => 'Portland'])->assertStatus(423);

        $this->assertSame('Vancouver', FundingApplication::find($this->applicationId)->business_city);
    }

    #[Test]
    public function a_partners_refused_edit_is_recorded_against_the_partner(): void
    {
        $this->setStatus(FundingApplication::CLEARED_TO_FUND);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])->assertStatus(423);

        $logs = $this->logsFor('Funding Application Edit Blocked');

        $this->assertCount(1, $logs);
        $this->assertSame('partner', $logs->first()->properties['actor']);
        $this->assertSame(FundingApplication::CLEARED_TO_FUND, $logs->first()->properties['blocked_status']);
    }

    #[Test]
    public function a_locked_application_is_not_re_openable_by_creating_a_second_row(): void
    {
        // Without an id in the payload the save used to fall through to "find a
        // draft, else create", which quietly produced a second application and
        // sidestepped the lock entirely.
        $this->setStatus(FundingApplication::SUBMITTED_TO_LENDER);

        $payload = $this->businessInfoPayload;
        unset($payload['id']);

        $this->postJson('/api/v1/common/funding-application/business-info', array_merge($payload, [
            'business_name' => 'Alpha Tech Solutions LLC',
        ]))->assertStatus(423);

        $this->assertSame(
            1,
            FundingApplication::where('funding_request_id', $this->fundingRequestId)->count(),
            'a blocked edit must not spawn a parallel application'
        );
    }

    // ── A partner's edit costs the borrower's signature ──────────────

    #[Test]
    public function a_partner_edit_in_underwriting_forces_the_borrower_to_resign(): void
    {
        $this->setStatus(FundingApplication::UNDERWRITING_REVIEW);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::DRAFT, $application->status);
        $this->assertTrue((bool) $application->requires_resign);
        $this->assertNull($application->signature_data, "the borrower's signature no longer covers this data");
        $this->assertFalse((bool) $application->attestation_authorization);
        $this->assertSame('Alpha Tech Solutions LLC', $application->business_name);
    }

    #[Test]
    public function a_partner_edit_is_recorded_as_the_partners_doing(): void
    {
        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])->assertOk();

        $logs = $this->logsFor('Funding Application Edited - Re-signature Required');

        $this->assertCount(1, $logs);

        $properties = $logs->first()->properties;

        $this->assertSame('partner', $properties['actor']);
        $this->assertSame(['business_name'], $properties['changed_fields']);
        $this->assertSame(FundingApplication::PENDING, $properties['previous_status']);
        $this->assertStringContainsString('re-signature required', strtolower($logs->first()->description));
    }

    #[Test]
    public function a_partner_resaving_an_untouched_step_costs_the_borrower_nothing(): void
    {
        $this->setStatus(FundingApplication::IN_VERIFICATION);

        $this->postBusinessInfo(['number_of_employees' => ''])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::IN_VERIFICATION, $application->status);
        $this->assertFalse((bool) $application->requires_resign);
        $this->assertNotNull($application->signature_data);
        $this->assertCount(0, $this->logsFor('Funding Application Edited - Re-signature Required'));
    }

    // ── What the partner portal is told ──────────────────────────────

    #[Test]
    public function the_api_warns_the_partner_that_an_edit_costs_the_borrowers_signature(): void
    {
        $data = $this->getJson('/api/v1/common/funding-application/funding-request?funding_request_id=' . $this->fundingRequestId)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['is_editable']);
        $this->assertTrue($data['edit_requires_resign'], 'the partner portal needs this to raise its confirmation first');
        $this->assertFalse($data['is_locked']);
    }

    #[Test]
    public function the_api_tells_the_partner_an_unsigned_application_costs_nothing_to_edit(): void
    {
        // A partner still preparing the application must not be interrupted on
        // every step of a first pass - nothing has been signed yet.
        $this->setStatus(FundingApplication::ON_HOLD);

        $data = $this->getJson('/api/v1/common/funding-application/funding-request?funding_request_id=' . $this->fundingRequestId)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['is_editable']);
        $this->assertFalse($data['edit_requires_resign']);
    }

    #[Test]
    public function the_api_reports_a_locked_application_as_read_only_to_the_partner(): void
    {
        $this->setStatus(FundingApplication::OFFER_ISSUED);

        $data = $this->getJson('/api/v1/common/funding-application/funding-request?funding_request_id=' . $this->fundingRequestId)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['is_locked']);
        $this->assertFalse($data['is_editable']);
        $this->assertNotNull($data['locked_reason']);
    }

    // ── The statuses a partner may still prepare against ─────────────

    #[Test]
    public function an_in_progress_application_is_still_promoted_from_on_hold_to_draft(): void
    {
        // Federal compliance is the last step; once it is on file the application
        // is complete and ready for the borrower to sign.
        $this->setStatus(FundingApplication::ON_HOLD);

        $this->postJson('/api/v1/common/funding-application/federal-compliance-disclosures', [
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'federal_compliance_disclosures_explanation' => 'No federal disclosures apply.',
            'filed_for_bankruptcy' => false,
            'any_pending_lawsuits' => false,
            'has_federal_loan' => false,
            'has_federal_debt' => false,
            'has_criminal_offense' => false,
            'has_parole_or_probation' => false,
            'suspended_or_debarred' => false,
            'any_outstanding_judgments_business' => false,
            'any_outstanding_judgments_owner' => false,
        ])->assertOk();

        $this->assertSame(
            FundingApplication::DRAFT,
            FundingApplication::find($this->applicationId)->status,
            'a completed draft must still become signable'
        );
    }
}
