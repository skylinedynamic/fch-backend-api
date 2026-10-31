<?php

namespace Tests\Feature\ActivityLog;

use App\Jobs\Reminder\DispatchReminderDueJob;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\Reminder;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * The assignee's own reminder of their task:
 *
 *   - without a reminder on the activity: 09:00 on the due day, their time -
 *     or at the due time itself when that is earlier
 *   - with one: at that reminder time, as a row of their own next to the
 *     author's, so each snoozes or dismisses their own
 *   - it follows edits (time, wording, assignee) and goes with the task
 */
class ActivityLogTaskReminderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsTaskAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTaskAssignmentFixtures();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        Passport::actingAs($this->hostAdmin, [], 'api');
    }

    private function remindAt(int $days = 2): string
    {
        return now(self::TIMEZONE)->addDays($days)->setTime(10, 0)->format('Y-m-d H:i:s');
    }

    private function remindAtUtc(string $local): string
    {
        return Carbon::parse($local, self::TIMEZONE)->utc()->toDateTimeString();
    }

    private function reminderOf(User $user): ?Reminder
    {
        return Reminder::where('created_by', $user->id)->where('created_by_entity_type', User::class)->first();
    }

    #[Test]
    public function without_a_reminder_the_assignee_is_reminded_on_the_morning_of_the_due_date(): void
    {
        $due = $this->dueDate(4);
        $id = $this->storeActivity($this->taskPayload(['task_due_date' => $due]))->assertCreated()->json('data.id');

        $this->assertSame(1, Reminder::count(), 'Only the assignee has one; the author set none.');
        $reminder = $this->reminderOf($this->partnerColleague);
        $this->assertSame($this->dueMorningUtc($due), $reminder->reminder_date_time->toDateTimeString());
        $this->assertSame($id, (int) $reminder->activity_log_id);
        $this->assertSame(Reminder::STATUS_PENDING, $reminder->status);
        $this->assertSame('Collect bank statements', $reminder->summary);
        $this->assertSame('Task', $reminder->reference);
        // Filed against the client, like the author's own reminders
        $this->assertSame($this->borrower->id, (int) $reminder->entity_id);
        $this->assertSame($reminder->id, ActivityLog::findOrFail($id)->properties['task_reminder_id']);

        Queue::assertPushed(DispatchReminderDueJob::class, fn ($job) => $job->reminderId === $reminder->id);
    }

    #[Test]
    public function a_task_due_before_nine_reminds_the_assignee_at_its_due_time(): void
    {
        $due = now(self::TIMEZONE)->addDays(4)->setTime(8, 15)->format('Y-m-d H:i:s');
        $this->storeActivity($this->taskPayload(['task_due_date' => $due]))->assertCreated();

        $this->assertSame($this->dueDateUtc($due), $this->reminderOf($this->partnerColleague)->reminder_date_time->toDateTimeString());
    }

    #[Test]
    public function with_a_reminder_the_author_and_the_assignee_each_get_their_own(): void
    {
        $remindAt = $this->remindAt();
        $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $remindAt]))->assertCreated();

        $this->assertSame(2, Reminder::count());
        $author = $this->reminderOf($this->hostAdmin);
        $assignee = $this->reminderOf($this->partnerColleague);
        $this->assertNotNull($author);
        $this->assertNotNull($assignee);
        $this->assertNotSame($author->id, $assignee->id);
        $this->assertSame($this->remindAtUtc($remindAt), $assignee->reminder_date_time->toDateTimeString());
        $this->assertSame($author->reminder_date_time->toDateTimeString(), $assignee->reminder_date_time->toDateTimeString());
    }

    #[Test]
    public function the_assignee_dismissing_theirs_leaves_the_authors(): void
    {
        $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt()]))->assertCreated();
        $assignee = $this->reminderOf($this->partnerColleague);

        Passport::actingAs($this->partnerColleague, [], 'api');
        $this->postJson("/api/v1/common/reminders/{$assignee->id}/dismiss")->assertOk();

        $this->assertSame(Reminder::STATUS_CANCELLED, $assignee->fresh()->status);
        $this->assertSame(Reminder::STATUS_PENDING, $this->reminderOf($this->hostAdmin)->status);
    }

    #[Test]
    public function no_assignee_reminder_for_your_own_task_or_an_unassigned_one(): void
    {
        $this->storeActivity($this->taskPayload(['assign_task' => '0']))->assertCreated();

        Passport::actingAs($this->partnerUser, [], 'api');
        $this->storeActivity($this->taskPayload([
            'assigned_to' => $this->partnerUser->id,
            'remind_me' => '1',
            'remind_at' => $this->remindAt(),
        ]))->assertCreated();

        $this->assertSame(1, Reminder::count(), "Just the author's own reminder.");
        $this->assertNotNull($this->reminderOf($this->partnerUser));
    }

    #[Test]
    public function a_new_due_date_reschedules_the_assignees_reminder(): void
    {
        $id = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $newDue = $this->dueDate(7);

        $this->updateActivity($id, $this->taskEdit(['task_due_date' => $newDue]))->assertOk();

        $this->assertSame(1, Reminder::count());
        $this->assertSame($this->dueMorningUtc($newDue), $this->reminderOf($this->partnerColleague)->reminder_date_time->toDateTimeString());
    }

    #[Test]
    public function a_new_assignee_gets_a_fresh_reminder_and_the_old_ones_goes(): void
    {
        $id = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $old = $this->reminderOf($this->partnerColleague);

        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $this->partnerUser->id]))->assertOk();

        $this->assertNull(Reminder::find($old->id));
        $new = $this->reminderOf($this->partnerUser);
        $this->assertNotNull($new);
        $this->assertSame(Reminder::STATUS_PENDING, $new->status);
        $this->assertSame($new->id, ActivityLog::findOrFail($id)->properties['task_reminder_id']);
    }

    #[Test]
    public function turning_the_reminder_on_and_off_moves_the_assignees_with_it(): void
    {
        $due = $this->dueDate(5);
        $id = $this->storeActivity($this->taskPayload(['task_due_date' => $due]))->assertCreated()->json('data.id');
        $remindAt = $this->remindAt(2);

        $this->updateActivity($id, ['remind_me' => '1', 'remind_at' => $remindAt])->assertOk();
        $this->assertSame(2, Reminder::count());
        $this->assertSame($this->remindAtUtc($remindAt), $this->reminderOf($this->partnerColleague)->reminder_date_time->toDateTimeString());

        $this->updateActivity($id, ['remind_me' => '0'])->assertOk();
        $this->assertNull($this->reminderOf($this->hostAdmin));
        $this->assertSame($this->dueMorningUtc($due), $this->reminderOf($this->partnerColleague)->reminder_date_time->toDateTimeString());
    }

    #[Test]
    public function a_wording_edit_keeps_a_snoozed_or_dismissed_reminder_as_it_is(): void
    {
        $id = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $reminder = $this->reminderOf($this->partnerColleague);
        $snoozedTo = now()->addDays(9)->startOfMinute();
        $reminder->update(['reminder_date_time' => $snoozedTo, 'status' => Reminder::STATUS_CANCELLED]);

        $this->updateActivity($id, ['subject' => 'Collect 6 months of statements'])->assertOk();

        $reminder->refresh();
        $this->assertSame('Collect 6 months of statements', $reminder->summary);
        $this->assertSame($snoozedTo->toDateTimeString(), $reminder->reminder_date_time->toDateTimeString());
        $this->assertSame(Reminder::STATUS_CANCELLED, $reminder->status);
    }

    #[Test]
    public function unassigning_or_leaving_task_removes_the_assignees_reminder(): void
    {
        $first = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $second = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $this->assertSame(2, Reminder::count());

        $this->updateActivity($first, ['reference' => 'Task', 'assign_task' => '0'])->assertOk();
        $this->updateActivity($second, ['reference' => 'Call'])->assertOk();

        $this->assertSame(0, Reminder::count());
        $this->assertNull(ActivityLog::findOrFail($first)->properties['task_reminder_id']);
    }

    #[Test]
    public function deleting_the_activity_removes_both_reminders(): void
    {
        $id = $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt()]))
            ->assertCreated()->json('data.id');
        $this->assertSame(2, Reminder::count());

        $this->withHeader('X-Timezone', self::TIMEZONE)->deleteJson("/api/v1/common/activity-logs/{$id}")->assertOk();

        $this->assertSame(0, Reminder::count());
    }

    #[Test]
    public function the_assignees_reminder_is_never_mistaken_for_the_authors(): void
    {
        $id = $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt()]))
            ->assertCreated()->json('data.id');
        $assignee = $this->reminderOf($this->partnerColleague);

        // An older row without reminder_id falls back to matching on the subject
        $props = ActivityLog::findOrFail($id)->properties;
        $this->reminderOf($this->hostAdmin)->delete();
        $props['reminder_id'] = null;
        ActivityLog::whereKey($id)->update(['properties' => json_encode($props)]);

        $this->updateActivity($id, ['remind_me' => '0'])->assertOk();

        $this->assertNotNull(Reminder::find($assignee->id), "Switching the author's reminder off leaves the assignee's due-date one.");
    }
}
