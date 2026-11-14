<?php

namespace Tests\Feature\FundingRequest;

use App\Http\Controllers\Api\V1\FCH\Common\FundingRequest\FundingRequestController;
use App\Models\Borrower\Borrower;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lender\Lender;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * R3-BUG-028: a funding request was approved with no lender, and
 * assign-lender sent with no lender wiped the matched lenders. Decision
 * (2026-11-15): approving a funding request needs an assigned lender, for
 * whoever approves it; assign-lender needs at least one lender.
 */
#[CoversClass(FundingRequestController::class)]
class FundingRequestApproveNeedsLenderTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $hostAdmin;
    private User $partnerAdmin;
    private int $lenderA;
    private int $lenderB;
    private int $requestId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($host);
        $this->partner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $borrower = $this->makeBorrower($this->partner);

        $this->lenderA = DB::table('lenders')->insertGetId(['name' => 'Fundbox', 'status' => Lender::STATUS_ACTIVE, 'created_at' => now(), 'updated_at' => now()]);
        $this->lenderB = DB::table('lenders')->insertGetId(['name' => 'OnDeck', 'status' => Lender::STATUS_ACTIVE, 'created_at' => now(), 'updated_at' => now()]);
        $sequenceId = DB::table('funding_sequencings')->insertGetId(['name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $this->requestId = DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'funding_sequence_id' => $sequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_PENDING,
            'lender_ids' => json_encode([$this->lenderA, $this->lenderB]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function approveAs(User $user)
    {
        $this->actingAsUser($user);

        return $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->requestId,
            'status' => FundingRequest::STATUS_APPROVED,
        ]);
    }

    private function request(): FundingRequest
    {
        return FundingRequest::findOrFail($this->requestId);
    }

    #[Test]
    public function assign_lender_with_no_lender_is_refused_and_keeps_the_matched_lenders(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->requestId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lender_ids');
        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->requestId, 'lender_ids' => []])
            ->assertStatus(422);

        $this->assertSame([$this->lenderA, $this->lenderB], $this->request()->lender_ids);
    }

    #[Test]
    public function the_host_cannot_approve_without_a_lender(): void
    {
        $this->approveAs($this->hostAdmin)
            ->assertStatus(422)
            ->assertJsonPath('message', __('funding_request.lender_required_to_approve'));

        $this->assertSame(FundingRequest::STATUS_PENDING, $this->request()->status);
    }

    #[Test]
    public function a_partner_cannot_approve_without_a_lender_either(): void
    {
        $this->approveAs($this->partnerAdmin)->assertStatus(422);

        $this->assertSame(FundingRequest::STATUS_PENDING, $this->request()->status);
    }

    #[Test]
    public function once_a_lender_is_assigned_the_request_is_approved(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->requestId, 'lender_ids' => [$this->lenderA]])->assertOk();

        $this->approveAs($this->hostAdmin)->assertOk();

        $this->assertSame(FundingRequest::STATUS_APPROVED, $this->request()->status);
        $this->assertSame($this->lenderA, (int) $this->request()->assign_lender);
    }

    #[Test]
    public function rejecting_needs_no_lender(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->requestId,
            'status' => FundingRequest::STATUS_REJECTED,
            'reject_reason' => 'Revenue below the minimum',
        ])->assertOk();

        $this->assertSame(FundingRequest::STATUS_REJECTED, $this->request()->status);
    }
}
