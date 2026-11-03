<?php

namespace Tests\Feature\Application;

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Http\Requests\Api\V1\FCH\Common\Application\AssignFundingApplicationLenderRequest;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Application\RequestApplicationService;
use App\Support\Audit\AuditLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Lender Information on the admin funding application details page (decision
 * 2026-11-03), as on the funding request details page:
 *
 * - Host only: matched lenders, and assigning one.
 * - Matching: an application with a funding request matches on the request's
 *   funding type (the request page's list); one without (converted from a loan
 *   application) on its Preferred and Additional Funding Types.
 * - One lender per application, shared with its funding request: assigning on
 *   either page is the same assignment. An application without a request keeps
 *   its own.
 * - In the 11 editable statuses (config `statuses.editable`) the lender is
 *   optional and the host may change it.
 * - Every status outside them needs a lender first; once one is assigned
 *   there, it cannot be changed. An application already outside them with no
 *   lender (set before this rule) can be given one, once.
 */
#[CoversClass(RequestApplicationService::class)]
#[CoversClass(RequestApplicationController::class)]
#[CoversClass(AssignFundingApplicationLenderRequest::class)]
#[CoversClass(FundingApplicationResource::class)]
#[CoversClass(FundingApplication::class)]
class FundingApplicationLenderAssignmentTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    private Partner $whiteLabel;

    private Borrower $borrower;

    private User $hostAdmin;

    private int $termLoanLenderId;

    private int $lineOfCreditLenderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->whiteLabel = $this->makeWhiteLabelPartner();
        $this->borrower = $this->makeBorrower($this->whiteLabel, [
            'fico_score' => 700,
            'monthly_revenue' => 20000,
            'business_start_date' => now()->subYears(3)->toDateString(),
        ]);
        $this->hostAdmin = $this->makePartnerAdmin($this->host);

        $this->termLoanLenderId = $this->lenderIn('Term Loan', 'Fundbox Financing', ['min_fico' => 450]);
        $this->lineOfCreditLenderId = $this->lenderIn('Business Line of Credit', 'LendingClub', ['min_fico' => 800]);
        $this->lenderIn('Equipment Financing', 'Balboa Capital');
    }

    // -- Matching ---------------------------------------------------------------

    #[Test]
    public function an_application_without_a_request_is_matched_on_its_preferred_and_additional_funding_types(): void
    {
        $id = $this->application(['preferred_funding_type' => 'Term Loan', 'additional_funding_types' => json_encode(['Business Line of Credit'])]);

        $data = $this->details($this->hostAdmin, $id);

        $names = array_column($data['matched_lenders'], 'name');
        sort($names);
        $this->assertSame(['Fundbox Financing', 'LendingClub'], $names);
        // Best match first, as on the funding request page.
        $this->assertSame('Fundbox Financing', $data['matched_lenders'][0]['name']);
        $this->assertSame([], $data['assigned_lender_ids']);
    }

    #[Test]
    public function an_application_with_a_request_is_matched_on_the_requests_funding_type(): void
    {
        $requestId = $this->request('Business Line of Credit');
        $id = $this->application(['funding_request_id' => $requestId, 'preferred_funding_type' => 'Term Loan']);

        $data = $this->details($this->hostAdmin, $id);

        $this->assertSame(['LendingClub'], array_column($data['matched_lenders'], 'name'));
    }

    #[Test]
    public function only_the_host_sees_lender_information(): void
    {
        $id = $this->application(['preferred_funding_type' => 'Term Loan']);

        $data = $this->details($this->makePartnerAdmin($this->whiteLabel), $id);

        $this->assertNull($data['matched_lenders']);
        $this->assertNull($data['assigned_lender_ids']);
        $this->assertFalse($data['can_assign_lender']);
    }

    // -- Assigning (decision 2026-11-03, final) ----------------------------------

    /** @return array<string, array{string}> */
    public static function editableStatuses(): array
    {
        return [
            'On Hold' => [FundingApplication::ON_HOLD],
            'Submitted - Pending Review' => [FundingApplication::PENDING],
            'Approved (Conditional)' => [FundingApplication::APPROVED_CONDITIONAL],
            'Approved (Final)' => [FundingApplication::APPROVED_FINAL],
        ];
    }

    /** @return array<string, array{string}> */
    public static function nonEditableStatuses(): array
    {
        return [
            'Matched to Lender' => [FundingApplication::MATCHED_TO_LENDER],
            'Offer Issued' => [FundingApplication::OFFER_ISSUED],
            'Funded' => [FundingApplication::FUNDED],
            'Denied' => [FundingApplication::DENIED],
            'Withdrawn' => [FundingApplication::WITHDRAWN],
            'Closed - Error' => [FundingApplication::CLOSED_ERROR],
        ];
    }

    /** In the 11 editable statuses the lender is optional, and the host may change it. */
    #[Test]
    #[DataProvider('editableStatuses')]
    public function in_an_editable_status_the_host_assigns_and_changes_the_lender(string $status): void
    {
        $id = $this->application(['status' => $status, 'preferred_funding_type' => 'Term Loan', 'additional_funding_types' => json_encode(['Business Line of Credit'])]);
        $this->actingAsUser($this->hostAdmin);

        $this->assertTrue($this->details($this->hostAdmin, $id)['can_assign_lender']);
        $this->assign($id, $this->termLoanLenderId)->assertOk();
        $this->assertTrue($this->details($this->hostAdmin, $id)['can_assign_lender']);
        $this->assign($id, $this->lineOfCreditLenderId)->assertOk()
            ->assertJsonPath('data.assigned_lender_ids', [$this->lineOfCreditLenderId]);

        $this->assertSame($this->lineOfCreditLenderId, (int) FundingApplication::findOrFail($id)->assign_lender);
    }

    /** Past the 11, the lender assigned is the lender: it cannot be changed. */
    #[Test]
    #[DataProvider('nonEditableStatuses')]
    public function past_the_editable_statuses_an_assigned_lender_cannot_be_changed(string $status): void
    {
        $id = $this->application([
            'status' => $status,
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => json_encode(['Business Line of Credit']),
            'lender_ids' => json_encode([$this->termLoanLenderId]),
            'assign_lender' => $this->termLoanLenderId,
        ]);
        $this->actingAsUser($this->hostAdmin);

        $this->assertFalse($this->details($this->hostAdmin, $id)['can_assign_lender']);
        $this->assign($id, $this->lineOfCreditLenderId)
            ->assertStatus(422)
            ->assertJsonPath('message', __('application.lender_locked', ['status' => $status]));

        $this->assertSame($this->termLoanLenderId, (int) FundingApplication::findOrFail($id)->assign_lender);
    }

    /** Already past the 11 with no lender (set before this rule): it can be given one, once. */
    #[Test]
    #[DataProvider('nonEditableStatuses')]
    public function past_the_editable_statuses_with_no_lender_one_can_still_be_assigned_once(string $status): void
    {
        $id = $this->application(['status' => $status, 'preferred_funding_type' => 'Term Loan', 'additional_funding_types' => json_encode(['Business Line of Credit'])]);
        $this->actingAsUser($this->hostAdmin);

        $this->assertTrue($this->details($this->hostAdmin, $id)['can_assign_lender']);
        $this->assign($id, $this->termLoanLenderId)->assertOk();

        $this->assertFalse($this->details($this->hostAdmin, $id)['can_assign_lender']);
        $this->assign($id, $this->lineOfCreditLenderId)->assertStatus(422);
        $this->assertSame($this->termLoanLenderId, (int) FundingApplication::findOrFail($id)->assign_lender);
    }

    #[Test]
    public function the_host_assigns_a_lender_to_an_application_without_a_request(): void
    {
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL, 'preferred_funding_type' => 'Term Loan']);
        $this->actingAsUser($this->hostAdmin);

        $this->assign($id, $this->termLoanLenderId)->assertOk()
            ->assertJsonPath('data.assigned_lender_ids', [$this->termLoanLenderId]);

        $application = FundingApplication::findOrFail($id);
        $this->assertSame($this->termLoanLenderId, (int) $application->assign_lender);
        $this->assertSame([$this->termLoanLenderId], $application->lender_ids);
        $this->assertTrue(ActivityLog::where('subject_type', FundingApplication::class)->where('subject_id', $id)
            ->where('event', 'Lender Assigned: Fundbox Financing')->exists());
    }

    #[Test]
    public function assigning_a_lender_is_recorded_in_the_audit_trail(): void
    {
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL, 'preferred_funding_type' => 'Term Loan']);
        $this->actingAsUser($this->hostAdmin);

        $this->assign($id, $this->termLoanLenderId)->assertOk();

        $event = FundingApplicationAuditEvent::where('funding_application_id', $id)
            ->where('event', FundingApplicationAuditEvent::EVENT_UPDATED)
            ->latest('id')
            ->firstOrFail();
        $changes = collect($event->changes)->keyBy('field');
        $this->assertSame((string) $this->termLoanLenderId, $changes['assign_lender']['new']);
        $this->assertNotNull($changes['lender_ids']['new']);
        $this->assertSame(__('audit.fields.assign_lender'), AuditLabels::field('assign_lender'));
        $this->assertSame(__('audit.fields.lender_ids'), AuditLabels::field('lender_ids'));
        $this->assertNotSame('audit.fields.lender_ids', __('audit.fields.lender_ids'));
    }

    #[Test]
    public function an_application_with_a_request_shares_the_requests_lender(): void
    {
        $requestId = $this->request('Business Line of Credit');
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL, 'funding_request_id' => $requestId]);
        $this->actingAsUser($this->hostAdmin);

        $this->assign($id, $this->lineOfCreditLenderId)->assertOk();

        $request = FundingRequest::findOrFail($requestId);
        $this->assertSame($this->lineOfCreditLenderId, (int) $request->assign_lender);
        $this->assertSame([$this->lineOfCreditLenderId], $request->lender_ids);
        $this->assertNull(FundingApplication::findOrFail($id)->assign_lender);
        $this->assertSame([$this->lineOfCreditLenderId], $this->details($this->hostAdmin, $id)['assigned_lender_ids']);
    }

    #[Test]
    public function the_requests_lender_is_locked_too_past_the_editable_statuses(): void
    {
        $requestId = $this->request('Term Loan', ['assign_lender' => $this->termLoanLenderId, 'lender_ids' => json_encode([$this->termLoanLenderId])]);
        $id = $this->application(['status' => FundingApplication::OFFER_ISSUED, 'funding_request_id' => $requestId]);
        $this->actingAsUser($this->hostAdmin);

        $this->assign($id, $this->lineOfCreditLenderId)->assertStatus(422);

        $this->assertSame($this->termLoanLenderId, (int) FundingRequest::findOrFail($requestId)->assign_lender);
    }

    #[Test]
    public function a_partner_cannot_assign_a_lender(): void
    {
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL, 'preferred_funding_type' => 'Term Loan']);
        $this->actingAsUser($this->makePartnerAdmin($this->whiteLabel));

        $this->assign($id, $this->termLoanLenderId)->assertForbidden();

        $this->assertNull(FundingApplication::findOrFail($id)->assign_lender);
    }

    // -- Leaving the editable statuses needs a lender ---------------------------

    /** Every status outside the 11 - Denied, Withdrawn and the Closed ones included. */
    #[Test]
    #[DataProvider('nonEditableStatuses')]
    public function a_status_past_the_editable_ones_cannot_be_set_without_a_lender(string $status): void
    {
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL]);
        $this->actingAsUser($this->hostAdmin);

        $this->setStatus($id, $status)
            ->assertStatus(422)
            ->assertJsonPath('message', __('application.lender_required_for_status', ['status' => $status]));

        $this->assertSame(FundingApplication::APPROVED_FINAL, FundingApplication::findOrFail($id)->status);
    }

    #[Test]
    #[DataProvider('nonEditableStatuses')]
    public function a_status_past_the_editable_ones_is_set_once_a_lender_is_assigned(string $status): void
    {
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL, 'preferred_funding_type' => 'Term Loan']);
        $this->actingAsUser($this->hostAdmin);
        $this->assign($id, $this->termLoanLenderId)->assertOk();

        $this->setStatus($id, $status)->assertOk();

        $this->assertSame($status, FundingApplication::findOrFail($id)->status);
    }

    #[Test]
    public function an_editable_status_needs_no_lender(): void
    {
        $id = $this->application(['status' => FundingApplication::PENDING]);
        $this->actingAsUser($this->hostAdmin);

        foreach ([FundingApplication::UNDERWRITING_REVIEW, FundingApplication::APPROVED_FINAL] as $status) {
            $this->setStatus($id, $status)->assertOk();
        }
    }

    #[Test]
    public function the_requests_lender_counts_for_an_application_with_a_request(): void
    {
        $requestId = $this->request('Term Loan', ['assign_lender' => $this->termLoanLenderId, 'lender_ids' => json_encode([$this->termLoanLenderId])]);
        $id = $this->application(['status' => FundingApplication::APPROVED_FINAL, 'funding_request_id' => $requestId]);
        $this->actingAsUser($this->hostAdmin);

        $this->setStatus($id, FundingApplication::MATCHED_TO_LENDER)->assertOk();
    }

    #[Test]
    public function the_api_lists_every_status_past_the_editable_ones_as_needing_a_lender(): void
    {
        $id = $this->application();

        $required = $this->details($this->hostAdmin, $id)['lender_required_statuses'];

        $this->assertContains(FundingApplication::DENIED, $required);
        $this->assertContains(FundingApplication::CLOSED_COMPLETED, $required);
        $this->assertNotContains(FundingApplication::APPROVED_FINAL, $required);
    }


    #[Test]
    public function assigning_to_an_application_that_does_not_exist_is_not_found(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->assign(999999, $this->termLoanLenderId)->assertNotFound();
    }

    /** An unexpected failure is logged and answered with a 500, not a stack trace. */
    #[Test]
    public function an_unexpected_failure_while_assigning_is_a_server_error(): void
    {
        $id = $this->application(['preferred_funding_type' => 'Term Loan']);
        $this->mock(RequestApplicationService::class, function ($service) {
            $service->shouldReceive('assignFundingApplicationLender')->andThrow(new RuntimeException('database went away'));
        });
        Log::spy();
        $this->actingAsUser($this->hostAdmin);

        $this->assign($id, $this->termLoanLenderId)
            ->assertStatus(500)
            ->assertJsonPath('message', __('application.error_assigning_lender'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $context['funding_application_id'] === $id
            && $context['error'] === 'database went away');
    }

    // -- Helpers ----------------------------------------------------------------

    private function details(User $user, int $id): array
    {
        $this->actingAsUser($user);

        return $this->getJson("/api/v1/common/request-application/funding-application/{$id}")->assertOk()->json('data');
    }

    private function assign(int $id, int $lenderId)
    {
        return $this->postJson("/api/v1/common/request-application/funding-application/{$id}/assign-lender", ['lender_ids' => [$lenderId]]);
    }

    private function setStatus(int $id, string $status)
    {
        return $this->postJson('/api/v1/common/request-application/funding-application/status-update', [
            'funding_application_id' => $id,
            'status' => $status,
            'funded_amount' => 50000,
        ]);
    }

    private function application(array $attributes = []): int
    {
        return DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->whiteLabel->id,
            'status' => FundingApplication::DRAFT,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function request(string $fundingType, array $attributes = []): int
    {
        $sequenceId = DB::table('funding_sequencings')->insertGetId(['name' => "{$fundingType} request", 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('funding_requests')->insertGetId(array_merge([
            'funding_sequence_id' => $sequenceId,
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->whiteLabel->id,
            'funding_type' => $fundingType,
            'requested_amount' => 30000,
            'status' => FundingRequest::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function lenderIn(string $category, string $name, array $requirements = []): int
    {
        $categoryId = DB::table('lender_product_categories')->where('name', $category)->value('id')
            ?? DB::table('lender_product_categories')->insertGetId(['name' => $category, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $lenderId = DB::table('lenders')->insertGetId(['name' => $name, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $sequenceId = DB::table('funding_sequencings')->insertGetId(['name' => "{$category} sequence", 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lender_visibilities')->insert(array_merge([
            'lender_id' => $lenderId,
            'funding_sequencing_id' => $sequenceId,
            'lender_product_category_id' => $categoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $requirements));

        return $lenderId;
    }
}
