<?php

namespace Tests\Feature\Application;

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
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
 * R3-BUG-016: a status note is internal unless the team ticks "Visible to
 * client". An internal note never reaches the borrower - not in their
 * Activity, not in their application data. The status change itself still does.
 */
#[CoversClass(RequestApplicationController::class)]
class FundingApplicationInternalStatusNoteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostStaff;
    private User $partnerAdmin;
    private User $borrowerUser;
    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->hostStaff = $this->makeStaffUser($this->host);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);
        $this->appId = DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $this->borrowerUser->entity_id,
            'partner_id' => $this->partner->id,
            'status' => FundingApplication::SUBMITTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function update(User $as, array $body)
    {
        $this->actingAsUser($as);

        return $this->postJson('/api/v1/common/request-application/funding-application/status-update', array_merge([
            'funding_application_id' => $this->appId,
            'status' => FundingApplication::UNDERWRITING_REVIEW,
        ], $body));
    }

    /** The application's activity descriptions a user may see, oldest first. */
    private function activitySeenBy(User $user): array
    {
        return ActivityLog::visibleTo($user)
            ->where('subject_type', FundingApplication::class)
            ->where('subject_id', $this->appId)
            ->orderBy('id')
            ->pluck('description')
            ->all();
    }

    private function to(): string
    {
        return ucwords(str_replace('_', ' ', FundingApplication::UNDERWRITING_REVIEW));
    }

    private function seesNote(User $user, string $note): bool
    {
        return collect($this->activitySeenBy($user))->contains(fn ($d) => str_contains((string) $d, $note));
    }

    #[Test]
    public function a_note_is_internal_unless_marked_visible(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'QA-TEST internal: lender X declined, try Y'])->assertOk();

        $this->assertSame(["Status updated to: {$this->to()}"], $this->activitySeenBy($this->borrowerUser));
        $this->assertTrue($this->seesNote($this->hostStaff, 'QA-TEST internal: lender X declined, try Y'));
        $this->assertFalse((bool) FundingApplication::find($this->appId)->status_note_visible_to_client);
    }

    #[Test]
    public function the_hosts_internal_note_is_for_the_hosts_team_only(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'Host only'])->assertOk();

        $this->assertFalse($this->seesNote($this->partnerAdmin, 'Host only'));
    }

    #[Test]
    public function a_partners_internal_note_is_for_the_partners_team_and_the_host(): void
    {
        $this->update($this->partnerAdmin, ['status_note' => 'Partner team only'])->assertOk();

        $this->assertTrue($this->seesNote($this->partnerAdmin, 'Partner team only'));
        $this->assertTrue($this->seesNote($this->hostStaff, 'Partner team only'));
        $this->assertSame(["Status updated to: {$this->to()}"], $this->activitySeenBy($this->borrowerUser));
    }

    #[Test]
    public function a_note_marked_visible_is_shown_to_the_client(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'Please upload bank statements', 'status_note_visible_to_client' => true])->assertOk();

        $this->assertSame(["Status updated to: {$this->to()}\nNote: Please upload bank statements"], $this->activitySeenBy($this->borrowerUser));
        $this->assertTrue((bool) FundingApplication::find($this->appId)->status_note_visible_to_client);
    }

    #[Test]
    public function an_internal_note_on_its_own_adds_nothing_for_the_client(): void
    {
        $this->update($this->hostStaff, [])->assertOk();
        $this->update($this->hostStaff, ['status_note' => 'Called the lender'])->assertOk();

        $this->assertSame(["Status updated to: {$this->to()}"], $this->activitySeenBy($this->borrowerUser));
        $this->assertTrue($this->seesNote($this->hostStaff, 'Called the lender'));
    }

    #[Test]
    public function the_client_gets_the_note_in_their_application_only_when_visible(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'Internal only'])->assertOk();
        // The borrower portal opens its own application through the common details route.
        $this->actingAsUser($this->borrowerUser);
        $this->getJson("/api/v1/common/request-application/funding-application/{$this->appId}")
            ->assertOk()
            ->assertJsonPath('data.status_note', null);

        $this->update($this->hostStaff, ['status_note' => 'For the client', 'status_note_visible_to_client' => true])->assertOk();
        $this->actingAsUser($this->borrowerUser);
        $this->getJson("/api/v1/common/request-application/funding-application/{$this->appId}")
            ->assertOk()
            ->assertJsonPath('data.status_note', 'For the client');
    }

    #[Test]
    public function the_team_gets_the_note_and_whether_the_client_sees_it(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'Internal only'])->assertOk();

        $this->actingAsUser($this->partnerAdmin);
        $this->getJson("/api/v1/common/request-application/funding-application/{$this->appId}")
            ->assertOk()
            ->assertJsonPath('data.status_note', 'Internal only')
            ->assertJsonPath('data.status_note_visible_to_client', false);
    }

    #[Test]
    public function showing_the_same_note_to_the_client_is_a_change_not_a_repeat(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'Docs in'])->assertOk();
        $this->update($this->hostStaff, ['status_note' => 'Docs in'])->assertOk();
        $this->assertSame(["Status updated to: {$this->to()}"], $this->activitySeenBy($this->borrowerUser));

        $this->update($this->hostStaff, ['status_note' => 'Docs in', 'status_note_visible_to_client' => true])->assertOk();
        $this->assertSame("Status note was updated.\nNote: Docs in", $this->activitySeenBy($this->borrowerUser)[1] ?? null);
    }

    #[Test]
    public function the_visible_flag_must_be_true_or_false(): void
    {
        $this->update($this->hostStaff, ['status_note' => 'x', 'status_note_visible_to_client' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status_note_visible_to_client');
    }
}
