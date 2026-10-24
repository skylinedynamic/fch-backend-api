<?php

namespace Tests\Feature\FundingRequest;

use App\Http\Controllers\Api\V1\FCH\Common\Funding\FundingQualificationController;
use App\Http\Controllers\Api\V1\FCH\Common\FundingRequest\FundingRequestController;
use App\Models\Borrower\Borrower;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's Funding Requests: /common/funding-request*, the funding
 * catalogue (/common/funding-sequencings/*) and a client's readiness for one
 * product (/common/funding-qualification/client-readiness-factors). A partner
 * works its own partner's requests; the host's team every partner's.
 */
#[CoversClass(FundingRequestController::class)]
#[CoversClass(FundingQualificationController::class)]
class FundingRequestReviewApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;
    private Borrower $myBorrower;
    private Borrower $theirBorrower;
    private int $sequenceId;
    private int $myRequestId;
    private int $theirRequestId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->myBorrower = $this->makeBorrower($this->partner);
        $this->theirBorrower = $this->makeBorrower($this->otherPartner, ['fico_score' => 801]);

        $this->sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Business Lines of Credit', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->myRequestId = $this->requestFor($this->myBorrower);
        $this->theirRequestId = $this->requestFor($this->theirBorrower);
    }

    private function requestFor(Borrower $borrower): int
    {
        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
            'funding_sequence_id' => $this->sequenceId,
            'funding_type' => 'Business Line of Credit',
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function statusOf(int $id): string
    {
        return DB::table('funding_requests')->where('id', $id)->value('status');
    }

    #[Test]
    public function the_funding_request_routes_need_a_signed_in_partner_user(): void
    {
        $this->postJson('/api/v1/common/funding-request')->assertUnauthorized();

        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $this->postJson('/api/v1/common/funding-request')->assertForbidden();
    }

    #[Test]
    public function the_list_holds_my_partners_requests_and_the_host_sees_all(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $ids = collect($this->postJson('/api/v1/common/funding-request')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$this->myRequestId], $ids->all());

        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->assertCount(2, $this->postJson('/api/v1/common/funding-request')->assertOk()->json('data'));
    }

    /**
     * Defect (BUG-008, OP-FREQ-04): the FUNDING SEQUENCE column sorted by the
     * sequence's id, not the name it shows, so the second (descending) click
     * gave "Colt Bright / Equipment Leasing / Vendor Credit / Business Credit".
     */
    #[Test]
    public function the_list_sorts_by_the_funding_sequence_name_both_ways(): void
    {
        // Created in an order that is not alphabetical, so id order != name order.
        $byName = ['Business Lines of Credit' => $this->myRequestId];
        foreach (['Vendor Credit', 'Business Credit', 'Equipment Leasing'] as $name) {
            $sequence = DB::table('funding_sequencings')->insertGetId(['name' => $name, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
            $request = $this->requestFor($this->myBorrower);
            DB::table('funding_requests')->where('id', $request)->update(['funding_sequence_id' => $sequence]);
            $byName[$name] = $request;
        }
        ksort($byName);
        $this->actingAsUser($this->partnerAdmin);

        $sorted = fn (string $order) => collect($this->postJson('/api/v1/common/funding-request', [
            'sort_field' => 'funding_sequence_id',
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame(array_values($byName), $sorted('asc'));
        $this->assertSame(array_reverse(array_values($byName)), $sorted('desc'));
    }

    #[Test]
    public function the_list_narrows_to_one_client(): void
    {
        $this->requestFor($this->makeBorrower($this->partner));
        $this->actingAsUser($this->partnerAdmin);

        $ids = collect($this->postJson('/api/v1/common/funding-request', ['borrower_id' => $this->myBorrower->id])->assertOk()->json('data'))
            ->pluck('id');

        $this->assertSame([$this->myRequestId], $ids->all());
    }

    #[Test]
    public function a_partner_opens_its_own_request_but_not_another_partners(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/funding-request/{$this->myRequestId}")->assertOk()->assertJsonPath('data.id', $this->myRequestId);
        $this->getJson("/api/v1/common/funding-request/{$this->theirRequestId}")->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function a_partner_approves_its_own_request(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->myRequestId,
            'status' => FundingRequest::STATUS_APPROVED,
        ])->assertOk();

        $this->assertSame(FundingRequest::STATUS_APPROVED, $this->statusOf($this->myRequestId));
    }

    #[Test]
    public function a_rejection_needs_a_reason_and_the_status_must_be_known(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/status-update', ['id' => $this->myRequestId, 'status' => FundingRequest::STATUS_REJECTED])
            ->assertStatus(422)->assertJsonValidationErrors(['reject_reason']);
        $this->postJson('/api/v1/common/funding-request/status-update', ['id' => $this->myRequestId, 'status' => 'Maybe'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    #[Test]
    public function a_partner_cannot_change_another_partners_request(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->theirRequestId,
            'status' => FundingRequest::STATUS_APPROVED,
        ])->assertJsonPath('status', false);

        $this->assertSame(FundingRequest::STATUS_PENDING, $this->statusOf($this->theirRequestId));
    }

    #[Test]
    public function the_host_moves_any_partners_request(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->theirRequestId,
            'status' => FundingRequest::STATUS_IN_PROGRESS,
        ])->assertOk();

        $this->assertSame(FundingRequest::STATUS_IN_PROGRESS, $this->statusOf($this->theirRequestId));
    }

    #[Test]
    public function lenders_are_assigned_to_my_own_request_only(): void
    {
        $lenderId = DB::table('lenders')->insertGetId(['name' => 'Big Bank', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->myRequestId, 'lender_ids' => [$lenderId]])->assertOk();
        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->theirRequestId, 'lender_ids' => [$lenderId]])
            ->assertJsonPath('status', false);
        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->myRequestId, 'lender_ids' => [999999]])
            ->assertStatus(422);
    }

    #[Test]
    public function the_funding_catalogue_loads(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-sequencings/fetch')->assertOk();
        $this->getJson('/api/v1/common/funding-sequencings/grouped')->assertOk();
        $this->getJson("/api/v1/common/funding-sequencings/client-funding-options/{$this->myBorrower->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['groups', 'types', 'stats']]);
    }

    #[Test]
    public function a_partner_reads_its_own_clients_readiness_for_a_product(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/funding-qualification/client-readiness-factors/{$this->myBorrower->id}/{$this->sequenceId}")
            ->assertOk();
    }

    /**
     * Defect: the borrower was looked up with a bare find(), so any partner
     * could read how another partner's client measures up - credit, revenue,
     * the reasons a product is off the table.
     */
    #[Test]
    public function a_partner_cannot_read_another_partners_clients_readiness(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/funding-qualification/client-readiness-factors/{$this->theirBorrower->id}/{$this->sequenceId}")
            ->assertNotFound();
    }
}
