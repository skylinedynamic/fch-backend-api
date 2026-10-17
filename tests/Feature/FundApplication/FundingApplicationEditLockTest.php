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
 * Once an application is with a lender, under contract, funded, closed or denied
 * it stops being the borrower's to edit - the figures a lender is underwriting
 * cannot move underneath them. Before that point the form stays open, but any
 * real change to data the borrower has already signed for withdraws that
 * signature and sends the application back to Draft to be signed again.
 *
 * FundingApplicationResignTest already covers the Pending Review case these
 * rules grew out of; this covers the lock, the statuses past Pending, and the
 * audit trail that has to be able to answer "who changed what, and when".
 */
class FundingApplicationEditLockTest extends TestCase
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

        // Signing writes the signed copy and its certificate; never into real storage.
        \Illuminate\Support\Facades\Storage::fake('public');

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
                'application_number' => 'FUND-TEST-LOCK',
                'borrower_id' => $this->borrowerId,
                'partner_id' => $this->partnerId,
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
            'name' => 'Michael Chen',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::BORROWER,
            'entity_type' => \App\Models\Borrower\Borrower::class,
            'entity_id' => $this->borrowerId,
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
            '/api/v1/borrower/funding-application/business-info',
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

    // ── The lock ─────────────────────────────────────────────────────

    #[Test]
    public function a_borrower_cannot_edit_an_application_that_is_with_a_lender(): void
    {
        $this->setStatus(FundingApplication::SUBMITTED_TO_LENDER);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])
            ->assertStatus(423);

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame('Alpha Tech Solutions', $application->business_name, 'nothing may be written');
        $this->assertSame(FundingApplication::SUBMITTED_TO_LENDER, $application->status);
    }

    #[Test]
    public function a_borrower_cannot_edit_a_denied_application(): void
    {
        $this->setStatus(FundingApplication::DENIED);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])
            ->assertStatus(423);

        $this->assertSame('Alpha Tech Solutions', FundingApplication::find($this->applicationId)->business_name);
    }

    #[Test]
    public function the_refusal_tells_the_borrower_which_status_closed_the_form(): void
    {
        $this->setStatus(FundingApplication::FUNDED);

        $response = $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])
            ->assertStatus(423);

        $this->assertStringContainsString(FundingApplication::FUNDED, $response->json('message'));
    }

    #[Test]
    public function a_refused_edit_is_recorded(): void
    {
        $this->setStatus(FundingApplication::OFFER_ACCEPTED);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])
            ->assertStatus(423);

        $logs = $this->logsFor('Funding Application Edit Blocked');

        $this->assertCount(1, $logs);
        $this->assertSame(FundingApplication::OFFER_ACCEPTED, $logs->first()->properties['blocked_status']);
        $this->assertSame('borrower', $logs->first()->properties['actor']);
    }

    #[Test]
    public function a_locked_application_cannot_even_be_re_submitted(): void
    {
        // Nothing signed can move a closed file back into review.
        $this->setStatus(FundingApplication::CLOSED_COMPLETED);

        $this->postJson('/api/v1/borrower/funding-application/attestation-authorization', [
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'signature_name' => 'Michael Chen',
            'timezone' => 'America/Los_Angeles',
            'esign_consent' => true,
            'terms_consent' => true,
            'credit_consent' => true,
            'legal_binding_consent' => true,
        ])->assertStatus(423);

        $this->assertSame(
            FundingApplication::CLOSED_COMPLETED,
            FundingApplication::find($this->applicationId)->status
        );
    }

    // ── Statuses past Pending Review still cost a signature ──────────

    #[Test]
    public function editing_an_application_in_underwriting_forces_a_resign(): void
    {
        $this->setStatus(FundingApplication::UNDERWRITING_REVIEW);

        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::DRAFT, $application->status);
        $this->assertTrue((bool) $application->requires_resign);
        $this->assertNull($application->signature_data);
        $this->assertFalse((bool) $application->attestation_authorization);
        $this->assertSame('Alpha Tech Solutions LLC', $application->business_name, 'the edit itself is still saved');
    }

    #[Test]
    public function editing_a_conditionally_approved_application_forces_a_resign(): void
    {
        $this->setStatus(FundingApplication::APPROVED_CONDITIONAL);

        $this->postBusinessInfo(['business_city' => 'Portland'])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::DRAFT, $application->status);
        $this->assertTrue((bool) $application->requires_resign);
    }

    #[Test]
    public function resaving_an_application_in_underwriting_unchanged_costs_nothing(): void
    {
        $this->setStatus(FundingApplication::UNDERWRITING_REVIEW);

        // The wizard re-posts every step's prefilled values, including '' for a
        // stored null. Paging through must not knock the file out of review.
        $this->postBusinessInfo(['number_of_employees' => ''])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, $application->status);
        $this->assertFalse((bool) $application->requires_resign);
        $this->assertNotNull($application->signature_data);
    }

    // ── The audit trail ──────────────────────────────────────────────

    #[Test]
    public function a_resign_forcing_edit_records_exactly_which_fields_moved(): void
    {
        $this->postBusinessInfo([
            'business_name' => 'Alpha Tech Solutions LLC',
            'business_city' => 'Portland',
        ])->assertOk();

        $logs = $this->logsFor('Funding Application Edited - Re-signature Required');

        $this->assertCount(1, $logs);

        $properties = $logs->first()->properties;

        $this->assertSame('borrower', $properties['actor']);
        $this->assertSame(FundingApplication::PENDING, $properties['previous_status']);
        $this->assertSame(FundingApplication::DRAFT, $properties['new_status']);
        $this->assertTrue($properties['requires_resign']);
        $this->assertEqualsCanonicalizing(
            ['business_name', 'business_city'],
            $properties['changed_fields']
        );
    }

    #[Test]
    public function a_resign_forcing_edit_records_the_old_and_new_value_of_each_field(): void
    {
        $before = FundingApplication::find($this->applicationId);

        $this->postBusinessInfo([
            'business_name' => 'Alpha Tech Solutions LLC',
            'business_city' => 'Portland',
        ])->assertOk();

        $log = $this->logsFor('Funding Application Edited - Re-signature Required')->first();
        $changes = $log->properties['changes'];

        $this->assertSame((string) $before->business_name, $changes['business_name']['from']);
        $this->assertSame('Alpha Tech Solutions LLC', $changes['business_name']['to']);
        $this->assertSame('Portland', $changes['business_city']['to']);
        $this->assertSame('Business Name', $changes['business_name']['label']);

        // And a person reading the log sees the same thing, not just column names.
        $this->assertStringContainsString('"Alpha Tech Solutions LLC"', $log->description);
        $this->assertStringContainsString('"Portland"', $log->description);
    }

    // ── The signature an edit clears ─────────────────────────────────

    #[Test]
    public function the_signature_an_edit_clears_is_kept_for_the_re_signing_step(): void
    {
        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])->assertOk();

        $application = FundingApplication::find($this->applicationId);
        $this->assertNull($application->signature_data);
        $this->assertSame('Michael Chen', $application->previous_signature_data['signature_name']);

        $attestation = $this->getJson(
            '/api/v1/borrower/funding-application/attestation-authorization?funding_request_id=' . $this->fundingRequestId
        )->assertOk()->json('data');

        // Nothing to prefill the new signature from, and the old one beside it.
        $this->assertNull($attestation['signature_data']);
        $this->assertTrue($attestation['requires_resign']);
        $this->assertSame('Michael Chen', $attestation['previous_signature']['signature_name']);
        $this->assertNotNull($attestation['previous_signature']['signed_at']);
    }

    #[Test]
    public function a_second_edit_before_re_signing_does_not_lose_the_previous_signature(): void
    {
        $this->postBusinessInfo(['business_name' => 'Alpha Tech Solutions LLC'])->assertOk();
        $this->postBusinessInfo(['business_city' => 'Portland'])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame('Michael Chen', $application->previous_signature_data['signature_name']);
    }

    #[Test]
    public function a_signed_application_reports_no_previous_signature(): void
    {
        $attestation = $this->getJson(
            '/api/v1/borrower/funding-application/attestation-authorization?funding_request_id=' . $this->fundingRequestId
        )->assertOk()->json('data');

        $this->assertFalse($attestation['requires_resign']);
        $this->assertNull($attestation['previous_signature']);
        $this->assertSame('Michael Chen', $attestation['signature_data']['signature_name']);
    }

    #[Test]
    public function an_edit_that_changes_nothing_writes_no_log(): void
    {
        $this->postBusinessInfo(['number_of_employees' => ''])->assertOk();

        $this->assertCount(0, $this->logsFor('Funding Application Edited - Re-signature Required'));
    }

    #[Test]
    public function signing_again_after_an_edit_is_logged_as_a_re_submission(): void
    {
        // The state a real edit leaves behind.
        FundingApplication::find($this->applicationId)->update([
            'status' => FundingApplication::DRAFT,
            'signature_data' => null,
            'attestation_authorization' => false,
            'requires_resign' => true,
        ]);

        $this->postJson('/api/v1/borrower/funding-application/attestation-authorization', [
            'id' => $this->applicationId,
            'funding_request_id' => $this->fundingRequestId,
            'signature_name' => 'Michael Chen',
            'timezone' => 'America/Los_Angeles',
            'esign_consent' => true,
            'terms_consent' => true,
            'credit_consent' => true,
            'legal_binding_consent' => true,
        ])->assertOk();

        $application = FundingApplication::find($this->applicationId);

        $this->assertSame(FundingApplication::PENDING, $application->status);
        $this->assertFalse((bool) $application->requires_resign);

        $logs = $this->logsFor('Funding Application Re-signed and Re-submitted');

        $this->assertCount(1, $logs, 'a re-submission must read differently from a first submission');
        $this->assertTrue($logs->first()->properties['is_resubmission']);
        $this->assertCount(0, $this->logsFor('Funding Application Submitted'));
    }

    // ── What the portal is told ──────────────────────────────────────

    #[Test]
    public function the_api_reports_a_locked_application_as_read_only(): void
    {
        $this->setStatus(FundingApplication::CONTRACTING_DOCS_OUT);

        $response = $this->getJson('/api/v1/borrower/funding-application/funding-request?funding_request_id=' . $this->fundingRequestId)
            ->assertOk();

        $data = $response->json('data');

        $this->assertTrue($data['is_locked']);
        $this->assertFalse($data['is_editable']);
        $this->assertNotNull($data['locked_reason']);
        // Out of the borrower's hands, so this round is settled either way -
        // they are free to start a new funding request.
        $this->assertTrue($data['can_start_new_funding_request']);
    }

    #[Test]
    public function the_api_warns_that_an_edit_will_cost_a_signature(): void
    {
        $response = $this->getJson('/api/v1/borrower/funding-application/funding-request?funding_request_id=' . $this->fundingRequestId)
            ->assertOk();

        $data = $response->json('data');

        $this->assertTrue($data['is_editable']);
        $this->assertTrue($data['edit_requires_resign'], 'the portal needs this to raise its confirmation first');
        $this->assertFalse($data['requires_resign']);
    }

    #[Test]
    public function the_api_opens_the_door_to_re_applying_once_an_application_is_over(): void
    {
        $this->setStatus(FundingApplication::DENIED);

        $data = $this->getJson('/api/v1/borrower/funding-application/funding-request?funding_request_id=' . $this->fundingRequestId)
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['can_start_new_funding_request']);
    }
}
