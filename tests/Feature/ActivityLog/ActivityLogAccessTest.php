<?php

namespace Tests\Feature\ActivityLog;

use App\Http\Controllers\Api\V1\Borrower\ActivityLog\BorrowerActivityLogController;
use App\Http\Controllers\Api\V1\FCH\Common\ActivityLog\ActivityLogController;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Reaching a single activity entry - by id, or through its attachments - is
 * held to the same rule as the activity list (ActivityLog::scopeVisibleTo()),
 * which ActivityLogVisibilityTest pins level by level. A borrower's
 * "everyone" entries are the ones about that borrower. (Both controllers report
 * an entry that cannot be reached the way they always reported a missing one:
 * 400, status false, no data.)
 */
#[CoversClass(ActivityLogController::class)]
#[CoversClass(BorrowerActivityLogController::class)]
class ActivityLogAccessTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private Partner $otherPartner;
    private User $partnerAdmin;
    private User $me;
    private Borrower $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->otherPartner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->neighbour = $this->makeBorrower($this->partner);
    }

    private function entryAbout(Borrower $borrower, string $visibility, array $attributes = []): ActivityLog
    {
        $author = $this->makePartnerAdmin(Partner::find($borrower->partner_id));

        return ActivityLog::create(array_merge([
            'log_name' => 'activity',
            'subject_type' => Borrower::class,
            'subject_id' => $borrower->id,
            'causer_id' => $author->id,
            'causer_type' => User::class,
            'visibility' => $visibility,
            'status' => 'log',
            'event' => 'Call notes',
            'description' => 'Call notes',
            'properties' => [
                'partner_id' => $borrower->partner_id,
                'borrower_id' => $borrower->id,
                'attachments' => ['files' => [['path' => 'attachments/notes.txt', 'name' => 'notes.txt']]],
            ],
        ], $attributes));
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    #[Test]
    public function a_partner_opens_an_entry_it_may_see(): void
    {
        $entry = $this->entryAbout($this->myBorrower(), ActivityLog::VISIBILITY_PARTNER);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/activity-logs/{$entry->id}")->assertOk()->assertJsonPath('data.id', $entry->id);
    }

    /**
     * Defect: show looked the entry up by id alone, so the list's visibility
     * rule could be stepped around - another partner's entries, and host
     * internal notes, opened straight by id.
     */
    #[Test]
    public function a_partner_cannot_open_an_entry_the_list_would_hide(): void
    {
        $theirs = $this->entryAbout($this->makeBorrower($this->otherPartner), ActivityLog::VISIBILITY_PARTNER);
        $internal = $this->entryAbout($this->myBorrower(), ActivityLog::VISIBILITY_INTERNAL);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/activity-logs/{$theirs->id}")->assertStatus(400)->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
        $this->getJson("/api/v1/common/activity-logs/{$internal->id}")->assertStatus(400)->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function an_attachment_is_out_of_reach_when_its_entry_is(): void
    {
        $theirs = $this->entryAbout($this->makeBorrower($this->otherPartner), ActivityLog::VISIBILITY_PARTNER);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/activity-logs/attachments/download', [
            'activity_log_id' => $theirs->id,
            'path' => 'attachments/notes.txt',
        ])->assertStatus(400)->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function the_list_answers_on_get_too(): void
    {
        $entry = $this->entryAbout($this->myBorrower(), ActivityLog::VISIBILITY_PARTNER);
        $hidden = $this->entryAbout($this->makeBorrower($this->otherPartner), ActivityLog::VISIBILITY_PARTNER);
        $this->actingAsUser($this->partnerAdmin);

        $ids = collect($this->getJson('/api/v1/common/activity-logs')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($entry->id, $ids->all());
        $this->assertNotContains($hidden->id, $ids->all());
    }

    #[Test]
    public function only_the_author_deletes_a_manual_entry(): void
    {
        $entry = $this->entryAbout($this->myBorrower(), ActivityLog::VISIBILITY_PARTNER);
        $author = User::find($entry->causer_id);

        $this->actingAsUser($this->partnerAdmin);
        $this->deleteJson("/api/v1/common/activity-logs/{$entry->id}")->assertForbidden();
        $this->assertNotNull($entry->fresh());

        $this->actingAsUser($author);
        $this->deleteJson("/api/v1/common/activity-logs/{$entry->id}")->assertOk();
        $this->assertNull(ActivityLog::find($entry->id));
    }

    #[Test]
    public function a_system_event_is_never_deleted(): void
    {
        $event = $this->entryAbout($this->myBorrower(), ActivityLog::VISIBILITY_PARTNER, [
            'properties' => ['partner_id' => $this->partner->id, 'borrower_id' => $this->me->entity_id, 'event_label' => 'Application submitted'],
        ]);
        $this->actingAsUser(User::find($event->causer_id));

        $this->deleteJson("/api/v1/common/activity-logs/{$event->id}")->assertForbidden();
        $this->assertNotNull($event->fresh());
    }

    #[Test]
    public function a_borrower_opens_an_everyone_entry_about_themselves(): void
    {
        $mine = $this->entryAbout($this->myBorrower(), ActivityLog::VISIBILITY_EVERYONE);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/activity-logs/{$mine->id}")->assertOk();
        $ids = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($mine->id, $ids->all());
    }

    /**
     * Defect: a borrower's "everyone" entries were not limited to entries about
     * that borrower, and the list's borrower_id filter came from the request -
     * so a borrower could list or open other borrowers' shared entries.
     */
    #[Test]
    public function a_borrower_does_not_reach_another_borrowers_entries(): void
    {
        $theirs = $this->entryAbout($this->neighbour, ActivityLog::VISIBILITY_EVERYONE);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/activity-logs/{$theirs->id}")->assertStatus(400)->assertJsonPath('status', false)->assertJsonMissingPath('data.id');
        $this->postJson('/api/v1/borrower/activity-logs/attachments/download', [
            'activity_log_id' => $theirs->id,
            'path' => 'attachments/notes.txt',
        ])->assertStatus(400)->assertJsonPath('status', false)->assertJsonMissingPath('data.id');

        foreach ([[], ['borrower_id' => $this->neighbour->id]] as $filters) {
            $ids = collect($this->postJson('/api/v1/borrower/activity-logs/fetch-all-activities', $filters)->assertOk()->json('data'))->pluck('id');
            $this->assertNotContains($theirs->id, $ids->all());
        }
    }
}
