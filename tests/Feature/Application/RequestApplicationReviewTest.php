<?php

namespace Tests\Feature\Application;

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;
use App\Models\Audit\FundingApplicationAuditEvent;
use App\Services\Audit\FundingApplicationAuditTrail;

/**
 * The partner portal's Intake & Review: /common/request-application/* - the
 * pre-qualification and funding application queues, their details, status
 * changes, audit history and downloads. A partner (and its staff, user_type
 * Partner) works its own book (partner_id); the host's team works every
 * partner's. The borrower portal also opens a borrower's own application
 * through funding-application/{id}.
 */
#[CoversClass(RequestApplicationController::class)]
class RequestApplicationReviewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;
    private User $borrowerUser;
    private int $myApplicationId;
    private int $theirApplicationId;
    private int $myPreQualId;
    private int $theirPreQualId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);

        $theirBorrower = $this->makeBorrower($this->otherPartner);
        $this->myApplicationId = $this->applicationFor($this->borrowerUser->entity_id, $this->partner->id);
        $this->theirApplicationId = $this->applicationFor($theirBorrower->id, $this->otherPartner->id);
        $this->myPreQualId = $this->preQualFor($this->borrowerUser->entity_id, $this->partner->id);
        $this->theirPreQualId = $this->preQualFor($theirBorrower->id, $this->otherPartner->id);
    }

    /**
     * A lender on the application: every status outside the editable ones needs
     * one first (FundingApplicationLenderAssignmentTest).
     */
    private function assignLenderTo(int $applicationId): void
    {
        $lenderId = DB::table('lenders')->insertGetId(['name' => 'Fundbox Financing', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('funding_applications')->where('id', $applicationId)
            ->update(['lender_ids' => json_encode([$lenderId]), 'assign_lender' => $lenderId]);
    }

    private function applicationFor(int $borrowerId, int $partnerId): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'status' => FundingApplication::SUBMITTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function preQualFor(int $borrowerId, int $partnerId): int
    {
        return DB::table('pre_qualification_requests')->insertGetId([
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'status' => PreQualificationRequest::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function url(string $path): string
    {
        return "/api/v1/common/request-application/{$path}";
    }

    #[Test]
    public function the_queues_hold_my_partners_book_and_the_host_sees_every_book(): void
    {
        $this->actingAsUser($this->partnerAdmin);
        $this->assertSame([$this->myApplicationId], collect($this->postJson($this->url('funding-application'))->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame([$this->myPreQualId], collect($this->postJson($this->url('pre-qualification'))->assertOk()->json('data'))->pluck('id')->all());

        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->assertCount(2, $this->postJson($this->url('funding-application'))->assertOk()->json('data'));
        $this->assertCount(2, $this->postJson($this->url('pre-qualification'))->assertOk()->json('data'));
    }

    #[Test]
    public function the_card_summaries_load(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url('funding-application/card-summary'))->assertOk();
        $this->getJson($this->url('pre-qualification/card-summary'))->assertOk();
    }

    #[Test]
    public function a_partner_opens_its_own_application_and_pre_qualification_but_not_another_partners(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url("funding-application/{$this->myApplicationId}"))->assertOk()->assertJsonPath('data.id', $this->myApplicationId);
        $this->getJson($this->url("pre-qualification/{$this->myPreQualId}"))->assertOk();

        $this->getJson($this->url("funding-application/{$this->theirApplicationId}"))->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
        $this->getJson($this->url("pre-qualification/{$this->theirPreQualId}"))->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function a_borrower_opens_their_own_application_from_the_borrower_portal(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $this->getJson($this->url("funding-application/{$this->myApplicationId}"))->assertOk()->assertJsonPath('data.id', $this->myApplicationId);
    }

    /**
     * Defect: the lookup was scoped only for user_type Partner, and this route
     * is open to the borrower portal - so a borrower could open any funding
     * application on the platform by id, co-borrowers' details included.
     */
    #[Test]
    public function a_borrower_cannot_open_anyone_elses_application(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $this->getJson($this->url("funding-application/{$this->theirApplicationId}"))
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('data.id');
    }

    /**
     * BUG-120. Defect: the funded date was the UTC day, so a deal closed at
     * 03:30 on 21 Oct in Dhaka (20 Oct 21:30 UTC) was funded on 20 Oct.
     */
    #[Test]
    public function a_funded_date_is_the_day_in_the_closers_timezone(): void
    {
        $this->assignLenderTo($this->myApplicationId);
        $this->actingAsUser($this->partnerAdmin);
        Carbon::setTestNow(Carbon::parse('2026-10-20 21:30:00', 'UTC'));

        $this->withHeader('X-Timezone', 'Asia/Dhaka')->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'funded_amount' => 50000,
        ])->assertOk();
        Carbon::setTestNow();

        $this->assertStringStartsWith('2026-10-21', (string) DB::table('funding_applications')->where('id', $this->myApplicationId)->value('funded_date'));
    }

    /** The details page's Closed (Completed) summary shows when it was funded. */
    #[Test]
    public function the_details_carry_the_funded_date(): void
    {
        DB::table('funding_applications')->where('id', $this->myApplicationId)
            ->update(['status' => FundingApplication::CLOSED_COMPLETED, 'total_funded_amount' => 50000, 'funded_date' => '2026-10-21']);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url("funding-application/{$this->myApplicationId}"))
            ->assertOk()
            ->assertJsonPath('data.funded_date', '2026-10-21')
            ->assertJsonPath('data.total_funded_amount', '50000.00');
    }

    /** A status change, and a note on its own, are written to the activity log. */
    #[Test]
    public function a_status_change_and_a_note_are_logged(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
            'status_note' => 'Docs in',
        ])->assertOk();
        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
            'status_note' => 'Still waiting on bank statements',
        ])->assertOk();

        $logs = DB::table('activity_log')
            ->where('log_name', 'funding_application_status_update')
            ->where('subject_id', $this->myApplicationId)
            ->orderBy('id')
            ->get(['event', 'description']);

        $from = ucwords(str_replace('_', ' ', FundingApplication::SUBMITTED));
        $to = ucwords(str_replace('_', ' ', FundingApplication::UNDERWRITING_REVIEW));
        $this->assertSame("Funding Application Status Changed: {$from} → {$to}", $logs[0]->event);
        $this->assertSame("Status updated to: {$to}\nNote: Docs in", $logs[0]->description);
        $this->assertSame('Funding Application Note Updated', $logs[1]->event);
        $this->assertSame("Status note was updated.\nNote: Still waiting on bank statements", $logs[1]->description);
    }

    #[Test]
    public function a_partner_moves_its_own_applications_through_review(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('pre-qualification/status-update'), [
            'id' => $this->myPreQualId,
            'status' => PreQualificationRequest::IN_REVIEW,
            'status_note' => 'Looking at it',
        ])->assertOk();
        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
        ])->assertOk();

        $this->assertSame(PreQualificationRequest::IN_REVIEW, DB::table('pre_qualification_requests')->where('id', $this->myPreQualId)->value('status'));
        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, DB::table('funding_applications')->where('id', $this->myApplicationId)->value('status'));
    }

    /**
     * SCRUM-331: every new status can be chosen from the dropdown and is saved,
     * here Withdrawn from Matched to Lender, with who withdrew and why in Status
     * Notes. It locks the file, books no funded amount and is in the audit history.
     */
    #[Test]
    public function an_application_is_moved_to_each_new_status(): void
    {
        $this->assignLenderTo($this->myApplicationId);
        $this->actingAsUser($this->partnerAdmin);

        foreach ([
            FundingApplication::PENDING_CLARIFICATION,
            FundingApplication::READY_FOR_SUBMISSION,
            FundingApplication::OFFER_DECLINED_BY_BORROWER,
            FundingApplication::CLOSED_NO_RESPONSE,
            FundingApplication::CLOSED_INCOMPLETE_APPLICATION,
            FundingApplication::CLOSED_ERROR,
        ] as $status) {
            $this->postJson($this->url('funding-application/status-update'), ['funding_application_id' => $this->myApplicationId, 'status' => $status])->assertOk();
            $this->assertSame($status, FundingApplication::find($this->myApplicationId)->status);
        }

        DB::table('funding_applications')->where('id', $this->myApplicationId)->update(['status' => FundingApplication::MATCHED_TO_LENDER]);
        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::WITHDRAWN,
            'status_note' => 'Withdrawn by borrower: chose another lender.',
        ])->assertOk();

        $application = FundingApplication::find($this->myApplicationId);
        $this->assertSame(FundingApplication::WITHDRAWN, $application->status);
        $this->assertSame('Withdrawn by borrower: chose another lender.', $application->status_note);
        $this->assertTrue($application->isLockedForEdit());
        $this->assertNull($application->total_funded_amount);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => FundingApplication::class,
            'subject_id' => $this->myApplicationId,
            'event' => 'Funding Application Status Changed: Matched To Lender → Withdrawn',
        ]);
    }

    /**
     * Defect: Status Notes allow 10,000 characters, but the column was a
     * VARCHAR(255), so a longer note - such as who withdrew a file and why -
     * failed on save. (SQLite does not enforce the length, so the column type
     * is asserted too.)
     */
    #[Test]
    public function a_status_note_of_10000_characters_is_saved(): void
    {
        $this->assertSame('text', \Illuminate\Support\Facades\Schema::getColumnType('funding_applications', 'status_note'));
        $this->assignLenderTo($this->myApplicationId);
        $this->actingAsUser($this->partnerAdmin);
        $note = str_repeat('a', 10000);

        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::WITHDRAWN,
            'status_note' => $note,
        ])->assertOk();
        $this->assertSame($note, FundingApplication::find($this->myApplicationId)->status_note);

        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->myApplicationId,
            'status' => FundingApplication::WITHDRAWN,
            'status_note' => $note . 'a',
        ])->assertStatus(422);
    }

    /**
     * Defect: both status updates looked the record up by id alone, so any
     * partner could move another partner's applications - funded included,
     * which also books the commission.
     */
    #[Test]
    public function a_partner_cannot_change_another_partners_statuses(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('pre-qualification/status-update'), [
            'id' => $this->theirPreQualId,
            'status' => PreQualificationRequest::DECLINED,
        ])->assertJsonPath('status', false);
        $this->postJson($this->url('funding-application/status-update'), [
            'funding_application_id' => $this->theirApplicationId,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
        ])->assertJsonPath('status', false);

        $this->assertSame(PreQualificationRequest::PENDING, DB::table('pre_qualification_requests')->where('id', $this->theirPreQualId)->value('status'));
        $this->assertSame(FundingApplication::SUBMITTED, DB::table('funding_applications')->where('id', $this->theirApplicationId)->value('status'));
    }

    #[Test]
    public function a_status_must_be_a_known_one(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url('pre-qualification/status-update'), ['id' => $this->myPreQualId, 'status' => 'Vibes'])->assertStatus(422);
        $this->postJson($this->url('funding-application/status-update'), ['funding_application_id' => $this->myApplicationId, 'status' => 'Vibes'])->assertStatus(422);
    }

    #[Test]
    public function the_audit_trail_is_my_own_applications_only(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson($this->url("funding-application/{$this->myApplicationId}/audit-trail"))->assertOk();
        $this->getJson($this->url("funding-application/{$this->theirApplicationId}/audit-trail"))
            ->assertNotFound()
            ->assertJsonMissingPath('data.0');
    }

    /**
     * Defect: a stored download (the generated application PDF, SSNs and all)
     * was fetched by its history id alone. The audit trail hands out a kept
     * file only for an application the user may review.
     */
    #[Test]
    public function a_kept_file_is_fetched_only_for_my_own_application(): void
    {
        $theirs = app(FundingApplicationAuditTrail::class)->append([
            'funding_application_id' => $this->theirApplicationId,
            'partner_id' => DB::table('funding_applications')->where('id', $this->theirApplicationId)->value('partner_id'),
            'event' => FundingApplicationAuditEvent::EVENT_DOCUMENT_ADDED,
            'file_hash' => str_repeat('a', 64),
            'file_path' => 'audit/files/theirs.pdf',
        ]);
        Storage::disk('uploads')->put('audit/files/theirs.pdf', '%PDF theirs');
        $this->actingAsUser($this->partnerAdmin);

        foreach ([$this->myApplicationId, $this->theirApplicationId] as $applicationId) {
            $response = $this->getJson($this->url("funding-application/{$applicationId}/audit-trail/{$theirs->id}/file"));

            $this->assertNotSame(200, $response->status());
            $this->assertStringNotContainsString('%PDF theirs', (string) $response->getContent());
        }
    }

    #[Test]
    public function only_the_host_runs_the_ai_evaluation(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson($this->url("pre-qualification/{$this->myPreQualId}/run-ai"))->assertForbidden();
    }

    #[Test]
    public function the_application_pdf_downloads_for_my_own_application_only(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->get($this->url("funding-application/{$this->myApplicationId}/download-application"))->assertOk();
        $this->getJson($this->url("funding-application/{$this->theirApplicationId}/download-application"))->assertJsonPath('status', false);
    }
}
