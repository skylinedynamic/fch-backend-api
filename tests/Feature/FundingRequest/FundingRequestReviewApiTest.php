<?php

namespace Tests\Feature\FundingRequest;

use App\Services\FCH\Common\FundingRequest\FundingRequestService;
use App\Http\Controllers\Api\V1\FCH\Common\Funding\FundingQualificationController;
use App\Http\Controllers\Api\V1\FCH\Common\FundingRequest\FundingRequestController;
use App\Models\Borrower\Borrower;
use App\Models\Funding\LenderVisibility;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Lender\Lender;
use App\Models\Lender\LenderProductCategory;
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
 * The partner portal's Funding Requests: /common/funding-request*, the funding
 * catalogue (/common/funding-sequencings/*) and a client's readiness for one
 * product (/common/funding-qualification/client-readiness-factors). A partner
 * works its own partner's requests; the host's team every partner's.
 */
#[CoversClass(FundingRequest::class)]
#[CoversClass(Lender::class)]
#[CoversClass(FundingRequestService::class)]
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

    /**
     * Defect: R2 BUG-048 - request 48 open in two tabs: tab A approved it (the
     * borrower was told to proceed), then tab B, still showing Pending, rejected
     * it and the status silently flipped. A decision made on a page showing an
     * older status is refused; nothing changes and nobody is notified.
     */
    #[Test]
    public function a_decision_from_a_page_showing_an_older_status_is_refused(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->myRequestId,
            'status' => FundingRequest::STATUS_APPROVED,
            'expected_status' => FundingRequest::STATUS_PENDING,
        ])->assertOk();
        Notification::fake();
        Mail::fake();

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->myRequestId,
            'status' => FundingRequest::STATUS_REJECTED,
            'reject_reason' => 'Stale tab',
            'expected_status' => FundingRequest::STATUS_PENDING,
        ])->assertStatus(409)
            ->assertJsonPath('message', __('funding_request.status_changed_elsewhere', ['status' => FundingRequest::STATUS_APPROVED]))
            ->assertJsonPath('errors.status', FundingRequest::STATUS_APPROVED);

        $this->assertSame(FundingRequest::STATUS_APPROVED, $this->statusOf($this->myRequestId));
        $this->assertNull(DB::table('funding_requests')->where('id', $this->myRequestId)->value('reject_reason'));
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
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
     * Defect: the rejection note had no length limit - only the TEXT column's,
     * past which the save failed as a server error. It takes up to 10,000
     * characters, like a funding application's status note, and says so.
     */
    #[Test]
    public function a_rejection_note_takes_up_to_10000_characters(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->myRequestId, 'status' => FundingRequest::STATUS_REJECTED, 'reject_reason' => str_repeat('a', 10001),
        ])->assertStatus(422)->assertJsonPath('errors.reject_reason', __('funding_request.reject_reason_max', ['max' => 10000]));
        $this->assertSame(FundingRequest::STATUS_PENDING, $this->statusOf($this->myRequestId));

        $this->postJson('/api/v1/common/funding-request/status-update', [
            'id' => $this->myRequestId, 'status' => FundingRequest::STATUS_REJECTED, 'reject_reason' => str_repeat('a', 10000),
        ])->assertOk();
        $this->assertSame(10000, strlen(DB::table('funding_requests')->where('id', $this->myRequestId)->value('reject_reason')));
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

    /**
     * Defect: R2 BUG-008 - two lenders named "Kabbage" looked the same in
     * Lender Information, Assigned Lender and the activity log. Each is now
     * shown as "Lender Name - Legal Name"; `name` itself is unchanged.
     */
    #[Test]
    public function a_lender_is_shown_with_its_legal_name_on_the_request(): void
    {
        $category = LenderProductCategory::create(['name' => 'Business Line of Credit', 'status' => 'Active']);
        $first = Lender::create(['name' => 'Kabbage', 'legal_name' => 'Kabbage Inc.', 'status' => Lender::STATUS_ACTIVE]);
        $second = Lender::create(['name' => 'Kabbage', 'legal_name' => 'Kabbage Funding LLC', 'status' => Lender::STATUS_ACTIVE]);
        foreach ([$first, $second] as $lender) {
            LenderVisibility::create([
                'funding_sequencing_id' => $this->sequenceId,
                'lender_id' => $lender->id,
                'lender_product_category_id' => $category->id,
                'min_fico' => 600,
            ]);
        }
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->myRequestId, 'lender_ids' => [$second->id]])->assertOk();

        $detail = $this->getJson("/api/v1/common/funding-request/{$this->myRequestId}")->assertOk();
        $matched = collect($detail->json('data.matched_lenders'))->sortBy('id')->values();
        $this->assertSame(['Kabbage', 'Kabbage'], $matched->pluck('name')->all());
        $this->assertSame(['Kabbage - Kabbage Inc.', 'Kabbage - Kabbage Funding LLC'], $matched->pluck('display_name')->all());
        $this->assertSame('Kabbage - Kabbage Funding LLC', $detail->json('data.lender_names'));
        $this->assertSame('Lender Assigned: Kabbage - Kabbage Funding LLC', DB::table('activity_log')
            ->where('subject_type', FundingRequest::class)
            ->where('subject_id', $this->myRequestId)
            ->latest('id')
            ->value('event'));
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

    /**
     * Defect: R2 BUG-020 - a lender set to Inactive was still listed as a 4/4
     * Excellent Match with an Assign button (and in the "All Lenders" filter,
     * which is built from the same list), and assign-lender took it.
     */
    #[Test]
    public function an_inactive_lender_is_neither_matched_nor_assignable_but_an_existing_assignment_stays(): void
    {
        $category = LenderProductCategory::create(['name' => 'Business Line of Credit', 'status' => 'Active']);
        $active = Lender::create(['name' => 'Open Bank', 'status' => Lender::STATUS_ACTIVE]);
        $inactive = Lender::create(['name' => 'Closed Bank', 'status' => Lender::STATUS_ACTIVE]);
        foreach ([$active, $inactive] as $lender) {
            LenderVisibility::create([
                'funding_sequencing_id' => $this->sequenceId,
                'lender_id' => $lender->id,
                'lender_product_category_id' => $category->id,
                'min_fico' => 600,
            ]);
        }
        $this->actingAsUser($this->partnerAdmin);

        // Assigned while still active; then the host deactivates it.
        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->myRequestId, 'lender_ids' => [$inactive->id]])->assertOk();
        $inactive->update(['status' => Lender::STATUS_INACTIVE]);

        $detail = $this->getJson("/api/v1/common/funding-request/{$this->myRequestId}")->assertOk();
        $this->assertSame(['Open Bank'], array_column($detail->json('data.matched_lenders'), 'name'));
        $this->assertSame('Closed Bank', $detail->json('data.lender_names'));

        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->theirRequestId, 'lender_ids' => [$inactive->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lender_ids.0' => __('funding_request.lender_inactive')]);
        $this->postJson('/api/v1/common/funding-request/assign-lender', ['id' => $this->myRequestId, 'lender_ids' => [$active->id]])->assertOk();
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
