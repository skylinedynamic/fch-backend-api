<?php

namespace Tests\Feature\FundingRequest;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Every funding request a borrower submits is a new one. Nothing the borrower
 * already has for the same funding option - a pending request, a rejected one,
 * or an application in any status - blocks it, and no earlier request or
 * application is reused or changed: each submission is its own row.
 *
 * The /funding/:id card still receives `can_start_new_funding_request` and the
 * application fields (other screens read them); the borrower portal no longer
 * uses them to block or redirect a new request.
 */
class ReapplyAfterSettledApplicationTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private int $borrowerId;

    private int $partnerId;

    private int $fundingSequenceId;

    private int $fundingRequestId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner-'.uniqid().'@example.com',
            'phone_number' => '(111) 111-1111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->borrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'business_name' => 'Alpha Tech Solutions',
            'email' => 'borrower-'.uniqid().'@example.com',
            'phone_number' => '(546) 456-4520',
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fundingSequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit',
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The round already run: an approved request that produced an application.
        $this->fundingRequestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'funding_sequence_id' => $this->fundingSequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'use_of_funds' => 'Accounts Receivable',
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Michael Chen',
            'email' => 'user-'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'user_type' => User::BORROWER,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Passport::actingAs(User::findOrFail($userId), [], 'api');
    }

    private function giveApplicationStatus(string $status): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-REAPPLY-'.uniqid(),
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'funding_request_id' => $this->fundingRequestId,
            'funding_sequence_id' => $this->fundingSequenceId,
            'business_name' => 'Alpha Tech Solutions',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function submitNewRequest()
    {
        return $this->postJson('/api/v1/borrower/funding/requests', [
            'funding_sequence_id' => $this->fundingSequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 45000,
            'use_of_funds' => 'Working Capital',
        ]);
    }

    // ── Over and done with: re-apply ─────────────────────────────────

    #[Test]
    public function a_denied_application_lets_the_borrower_apply_again(): void
    {
        $this->giveApplicationStatus(FundingApplication::DENIED);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(
            2,
            FundingRequest::where('borrower_id', $this->borrowerId)->count(),
            'a fresh request, not an edit of the old one'
        );
        $this->assertSame(
            FundingRequest::STATUS_PENDING,
            FundingRequest::where('borrower_id', $this->borrowerId)->latest('id')->first()->status
        );
    }

    #[Test]
    public function a_closed_application_lets_the_borrower_apply_again(): void
    {
        $this->giveApplicationStatus(FundingApplication::CLOSED_COMPLETED);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
    }

    // ── Nothing already there blocks or changes a new request ────────

    /**
     * Defect: an application still in review here (On Hold, Draft, Pending,
     * Underwriting, ...) refused a new request with "You already have a funding
     * application in progress". Every request is now a new one, and the
     * existing application is left exactly as it was.
     */
    #[Test]
    public function an_application_still_in_review_does_not_block_a_new_request(): void
    {
        $applicationId = $this->giveApplicationStatus(FundingApplication::UNDERWRITING_REVIEW);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
        $application = FundingApplication::findOrFail($applicationId);
        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, $application->status);
        $this->assertSame($this->fundingRequestId, (int) $application->funding_request_id);
    }

    /**
     * Defect: an On Hold application (the one the card offered to "Edit") also
     * refused a new request.
     */
    #[Test]
    public function an_on_hold_application_does_not_block_a_new_request(): void
    {
        $applicationId = $this->giveApplicationStatus(FundingApplication::ON_HOLD);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
        $this->assertSame(FundingApplication::ON_HOLD, FundingApplication::findOrFail($applicationId)->status);
    }

    /**
     * Defect: a pending request for the same funding option refused a new one
     * with "Already applied. Please wait for approval or rejection."
     */
    #[Test]
    public function a_pending_request_does_not_block_a_new_request(): void
    {
        DB::table('funding_requests')->where('id', $this->fundingRequestId)
            ->update(['status' => FundingRequest::STATUS_PENDING]);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
    }

    /**
     * Defect: submitting after a rejection overwrote the rejected request in
     * place (amount, purpose, status, reason) instead of starting a new one.
     *
     * Still true for "Start Funding Request" on the funding option (no
     * `funding_request_id`). "Update Application" on the rejected request sends
     * its id and resubmits that request instead - see below.
     */
    #[Test]
    public function a_rejected_request_is_kept_and_a_new_request_is_created(): void
    {
        DB::table('funding_requests')->where('id', $this->fundingRequestId)->update([
            'status' => FundingRequest::STATUS_REJECTED,
            'reject_reason' => 'Revenue too low',
        ]);

        $response = $this->submitNewRequest()->assertStatus(201);

        $this->assertNotEquals($this->fundingRequestId, $response->json('data.id'));
        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());

        $old = FundingRequest::findOrFail($this->fundingRequestId);
        $this->assertSame(FundingRequest::STATUS_REJECTED, $old->status);
        $this->assertSame('Revenue too low', $old->reject_reason);
        $this->assertEquals(30000, $old->requested_amount);
    }

    private function rejectTheRequest(): void
    {
        DB::table('funding_requests')->where('id', $this->fundingRequestId)->update([
            'status' => FundingRequest::STATUS_REJECTED,
            'reject_reason' => 'Revenue too low',
            'approved_at' => now(),
            'approved_by' => 1,
        ]);
    }

    private function updateRejectedRequest(int $fundingRequestId)
    {
        return $this->postJson('/api/v1/borrower/funding/requests', [
            'funding_request_id' => $fundingRequestId,
            'funding_sequence_id' => $this->fundingSequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 45000,
            'use_of_funds' => 'Working Capital',
        ]);
    }

    /**
     * Defect: "Update Application" on a rejected request inserted a new funding
     * request every time it was submitted, leaving the rejected one behind -
     * one rejection turned into several rows for the same request.
     */
    #[Test]
    public function updating_a_rejected_request_resubmits_that_request_and_inserts_none(): void
    {
        $this->rejectTheRequest();

        $response = $this->updateRejectedRequest($this->fundingRequestId)->assertStatus(201);

        $this->assertSame($this->fundingRequestId, $response->json('data.id'));
        $this->assertSame(1, FundingRequest::where('borrower_id', $this->borrowerId)->count());

        $request = FundingRequest::findOrFail($this->fundingRequestId);
        $this->assertSame(FundingRequest::STATUS_PENDING, $request->status);
        $this->assertEquals(45000, $request->requested_amount);
        $this->assertSame('Working Capital', $request->use_of_funds);
        $this->assertNull($request->reject_reason);
        $this->assertNull($request->approved_at);
        $this->assertNull($request->approved_by);
    }

    /**
     * Only a rejected request can be updated. Once resubmitted it is Pending, so
     * sending the same update again (a double click, a second tab) inserts
     * nothing and changes nothing.
     */
    #[Test]
    public function a_request_that_is_not_rejected_cannot_be_updated(): void
    {
        $this->rejectTheRequest();
        $this->updateRejectedRequest($this->fundingRequestId)->assertStatus(201);

        $this->postJson('/api/v1/borrower/funding/requests', [
            'funding_request_id' => $this->fundingRequestId,
            'funding_sequence_id' => $this->fundingSequenceId,
            'requested_amount' => 12000,
        ])->assertStatus(422)->assertJsonValidationErrors('funding_request_id');

        $this->assertSame(1, FundingRequest::where('borrower_id', $this->borrowerId)->count());
        $this->assertEquals(45000, FundingRequest::findOrFail($this->fundingRequestId)->requested_amount);
    }

    #[Test]
    public function another_borrowers_rejected_request_cannot_be_updated(): void
    {
        $otherBorrowerId = DB::table('borrowers')->insertGetId([
            'first_name' => 'Other', 'last_name' => 'Borrower', 'email' => 'other-'.uniqid().'@example.com',
            'partner_id' => $this->partnerId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirsId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $otherBorrowerId,
            'partner_id' => $this->partnerId,
            'funding_sequence_id' => $this->fundingSequenceId,
            'requested_amount' => 20000,
            'status' => FundingRequest::STATUS_REJECTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->updateRejectedRequest($theirsId)->assertStatus(422)->assertJsonValidationErrors('funding_request_id');

        $this->assertSame(FundingRequest::STATUS_REJECTED, FundingRequest::findOrFail($theirsId)->status);
        $this->assertEquals(20000, FundingRequest::findOrFail($theirsId)->requested_amount);
    }

    #[Test]
    public function a_rejected_request_cannot_be_updated_onto_another_funding_option(): void
    {
        $this->rejectTheRequest();
        $otherSequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Term Loans', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/borrower/funding/requests', [
            'funding_request_id' => $this->fundingRequestId,
            'funding_sequence_id' => $otherSequenceId,
            'requested_amount' => 45000,
        ])->assertStatus(422)->assertJsonValidationErrors('funding_request_id');

        $this->assertSame(FundingRequest::STATUS_REJECTED, FundingRequest::findOrFail($this->fundingRequestId)->status);
    }

    /**
     * Defect: a resubmission after a rejection reused the old request's row,
     * so in the admin Funding Requests list (newest first) it sat below
     * anything submitted since instead of at the top.
     */
    #[Test]
    public function a_new_request_is_listed_first_for_the_admin(): void
    {
        DB::table('funding_requests')->where('id', $this->fundingRequestId)
            ->update(['status' => FundingRequest::STATUS_REJECTED]);
        // Submitted since, for another funding option.
        $otherSequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Term Loans', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $laterRequestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $this->borrowerId,
            'partner_id' => $this->partnerId,
            'funding_sequence_id' => $otherSequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 15000,
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newId = $this->submitNewRequest()->assertStatus(201)->json('data.id');

        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));
        $ids = collect($this->postJson('/api/v1/common/funding-request', ['sort_field' => 'id', 'sort_order' => 'desc'])
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$newId, $laterRequestId, $this->fundingRequestId], $ids);
    }

    #[Test]
    public function an_application_with_a_lender_lets_the_borrower_apply_again(): void
    {
        // Once the file is with a lender it is out of the borrower's hands, so
        // that round is settled from their side and a fresh request starts a new
        // one. It is applications still in *our* review that must not be doubled.
        $this->giveApplicationStatus(FundingApplication::SUBMITTED_TO_LENDER);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
    }

    #[Test]
    public function a_funded_application_lets_the_borrower_apply_again(): void
    {
        $this->giveApplicationStatus(FundingApplication::FUNDED);

        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
    }

    #[Test]
    public function every_status_out_of_the_borrowers_hands_lets_them_apply_again(): void
    {
        foreach (FundingApplication::lockedStatuses() as $status) {
            $this->assertTrue(
                in_array($status, FundingApplication::statusesAllowingNewRequest(), true),
                "{$status} must let the borrower submit a new funding request"
            );
        }
    }

    #[Test]
    public function an_approved_request_with_no_application_yet_is_untouched(): void
    {
        // Nothing has been started against the approved request, so the existing
        // "already applied" rules are all that apply - no application to check.
        $this->submitNewRequest()->assertStatus(201);

        $this->assertSame(2, FundingRequest::where('borrower_id', $this->borrowerId)->count());
    }

    // ── What the funding card is told ────────────────────────────────

    #[Test]
    public function the_funding_card_offers_a_new_request_only_once_the_application_is_over(): void
    {
        $applicationId = $this->giveApplicationStatus(FundingApplication::UNDERWRITING_REVIEW);

        $inReview = $this->fundingCardPayload();
        $this->assertFalse($inReview['can_start_new_funding_request'], 'still in review here, so no second request');
        $this->assertFalse($inReview['funding_application_is_locked']);
        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, $inReview['funding_application_status']);
        // Key 5, in the editable list: the card offers "Edit Application".
        $this->assertSame(5, $inReview['funding_application_status_key']);
        $this->assertTrue($inReview['funding_application_is_editable']);
        $this->assertNotNull($inReview['funding_application_funding_request_id']);

        DB::table('funding_applications')->where('id', $applicationId)
            ->update(['status' => FundingApplication::LENDER_REVIEWING]);

        $withLender = $this->fundingCardPayload();
        $this->assertTrue($withLender['can_start_new_funding_request']);
        $this->assertTrue($withLender['funding_application_is_locked']);
        $this->assertSame(FundingApplication::LENDER_REVIEWING, $withLender['funding_application_status']);
        // Key 14, not in the editable list: no editing, a new request instead.
        $this->assertSame(14, $withLender['funding_application_status_key']);
        $this->assertFalse($withLender['funding_application_is_editable']);
    }

    private function fundingCardPayload(): array
    {
        $response = $this->getJson('/api/v1/borrower/funding/'.$this->fundingSequenceId)->assertOk();

        $data = $response->json('data');

        return $data['funding'] ?? $data;
    }
}
