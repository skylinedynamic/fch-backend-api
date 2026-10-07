<?php

namespace Tests\Feature\FundingRequest;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A borrower may submit a new funding request once the application from the
 * last round has left their hands - matched or sent to a lender, under
 * contract, funded, closed or denied. All of those are settled from the
 * borrower's side, so a fresh request starts a new round rather than competing
 * with the old one.
 *
 * What must not happen is a second request opened while an application is still
 * being reviewed here (On Hold, Draft, Pending, Underwriting, Verification,
 * Approved): that would run two pipelines for the same borrower against the
 * same lenders.
 *
 * The /funding/:id card decides which of those to show from
 * `can_start_new_funding_request`; this pins down the server-side rule the card
 * reflects, which is what actually holds if someone posts directly.
 */
class ReapplyAfterSettledApplicationTest extends TestCase
{
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

    private function giveApplicationStatus(string $status): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-TEST-REAPPLY-' . uniqid(),
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

    // ── Still in flight: refuse ──────────────────────────────────────

    #[Test]
    public function an_application_still_in_review_blocks_a_second_request(): void
    {
        $this->giveApplicationStatus(FundingApplication::UNDERWRITING_REVIEW);

        $response = $this->submitNewRequest()->assertStatus(500);

        $this->assertStringContainsString('already have a funding application in progress', $response->json('message'));
        $this->assertSame(1, FundingRequest::where('borrower_id', $this->borrowerId)->count());
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
        // Key 5 of 9 editable: the card offers "Edit Application".
        $this->assertSame(5, $inReview['funding_application_status_key']);
        $this->assertTrue($inReview['funding_application_is_editable']);
        $this->assertNotNull($inReview['funding_application_funding_request_id']);

        DB::table('funding_applications')->where('id', $applicationId)
            ->update(['status' => FundingApplication::LENDER_REVIEWING]);

        $withLender = $this->fundingCardPayload();
        $this->assertTrue($withLender['can_start_new_funding_request']);
        $this->assertTrue($withLender['funding_application_is_locked']);
        $this->assertSame(FundingApplication::LENDER_REVIEWING, $withLender['funding_application_status']);
        // Key 12, past the limit: no editing, a new request instead.
        $this->assertSame(12, $withLender['funding_application_status_key']);
        $this->assertFalse($withLender['funding_application_is_editable']);
    }

    private function fundingCardPayload(): array
    {
        $response = $this->getJson('/api/v1/borrower/funding/' . $this->fundingSequenceId)->assertOk();

        $data = $response->json('data');

        return $data['funding'] ?? $data;
    }
}
