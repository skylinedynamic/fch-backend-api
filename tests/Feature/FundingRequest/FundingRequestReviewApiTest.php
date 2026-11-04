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

    /**
     * Defect (BUG-159, OP-FREQ-14): the reason a request was rejected was saved
     * and shown to the client, but no staff screen showed it - the activity
     * entry read only "Status updated to: Rejected". The entry names the
     * reason, and the detail the staff page reads carries it.
     */
    #[Test]
    public function a_rejection_keeps_its_reason_on_the_detail_and_in_the_activity(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $reason = 'Please add 3 months of bank statements';

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->myRequestId,
            'status' => FundingRequest::STATUS_REJECTED,
            'reject_reason' => $reason,
        ])->assertOk();

        $this->getJson("/api/v1/common/funding-request/{$this->myRequestId}")
            ->assertOk()
            ->assertJsonPath('data.reject_reason', $reason);

        $description = DB::table('activity_log')
            ->where('subject_type', FundingRequest::class)
            ->where('subject_id', $this->myRequestId)
            ->where('log_name', 'funding_request_status_update')
            ->latest('id')
            ->value('description');
        $this->assertStringContainsString("Reason: {$reason}", (string) $description);
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

    /**
     * Defect (BUG-070): the activity entry read "Lenders Assigned to Request"
     * with the lender only in the collapsed description, so two assignments
     * could not be told apart.
     */
    /**
     * Defect (BUG-070): the tracker had only created_at and updated_at, so once
     * the request was approved the dates of the steps in between were gone. The
     * detail carries when each status was reached (from the status-change log).
     */
    #[Test]
    public function the_detail_keeps_the_date_each_status_was_reached(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $steps = [
            FundingRequest::STATUS_ACCEPTED => '2026-10-20 09:00:00',
            FundingRequest::STATUS_IN_PROGRESS => '2026-10-21 10:30:00',
            FundingRequest::STATUS_APPROVED => '2026-10-22 15:45:00',
        ];
        foreach ($steps as $status => $at) {
            $this->travelTo(\Carbon\Carbon::parse($at));
            $this->postJson('/api/v1/common/funding-request/status-update', ['id' => $this->myRequestId, 'status' => $status])->assertOk();
            $this->travelBack();
        }

        $dates = $this->getJson("/api/v1/common/funding-request/{$this->myRequestId}")->assertOk()->json('data.status_dates');

        foreach ($steps as $status => $at) {
            $this->assertSame($at, \Carbon\Carbon::parse($dates[$status])->format('Y-m-d H:i:s'), $status);
        }
    }

    #[Test]
    public function the_assignment_activity_names_the_lender(): void
    {
        $lenderId = DB::table('lenders')->insertGetId(['name' => 'Big Bank', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->myRequestId, 'lender_ids' => [$lenderId]])->assertOk();

        $this->assertSame('Lender Assigned: Big Bank', DB::table('activity_log')
            ->where('subject_type', FundingRequest::class)
            ->where('subject_id', $this->myRequestId)
            ->latest('id')
            ->value('event'));
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
