<?php

namespace Tests\Feature\ActivityLog;

use App\Mail\ActivityLog\TaskAssignedMail;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\Reminder;
use App\Models\User\User;
use App\Notifications\DynamicNotification;
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
 * A Task's creator and assignee each have their own reminder row, but the
 * task acts as one:
 *
 *   - only the creator reschedules it, and that moves the activity's date and
 *     both people's reminders (not the due date);
 *   - snooze and dismiss stay each person's own;
 *   - a change to the date, due date, priority or status tells the other
 *     person, in the bell and by email.
 */
class ActivityLogTaskSyncTest extends TestCase
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
    }

    private function remindAt(int $days = 2): string
    {
        return now(self::TIMEZONE)->addDays($days)->setTime(10, 0)->format('Y-m-d H:i:s');
    }

    private function utc(string $local): string
    {
        return Carbon::parse($local, self::TIMEZONE)->utc()->toDateTimeString();
    }

    /** A Task by the host admin, assigned to the partner's colleague, both reminded. */
    private function storedTask(array $overrides = []): int
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $id = $this->storeActivity($this->taskPayload(array_merge([
            'remind_me' => '1',
            'remind_at' => $this->remindAt(),
            'reminder_recipient' => 'both',
        ], $overrides)))->assertCreated()->json('data.id');

        // Only what happens after creating the task is under test
        Mail::fake();
        Notification::fake();

        return $id;
    }

    private function reminderOf(User $user): Reminder
    {
        return Reminder::where('created_by', $user->id)->where('created_by_entity_type', User::class)->firstOrFail();
    }

    private function reschedule(User $user, Reminder $reminder, string $at, int $leadMinutes = 30)
    {
        Passport::actingAs($user, [], 'api');

        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->postJson("/api/v1/common/reminders/{$reminder->id}/snooze", [
                'reschedule_at' => $at,
                'lead_minutes' => $leadMinutes,
                'note' => 'Client asked for Friday',
            ]);
    }

    private function bellsTo(User $user)
    {
        return Notification::sent($user, DynamicNotification::class);
    }

    #[Test]
    public function the_creator_rescheduling_moves_the_activity_and_both_reminders_but_not_the_due_date(): void
    {
        $id = $this->storedTask();
        $dueBefore = ActivityLog::findOrFail($id)->properties['task']['due_date'];
        $newAt = now(self::TIMEZONE)->addDays(5)->setTime(14, 0)->format('Y-m-d H:i:s');
        $newRemind = now(self::TIMEZONE)->addDays(5)->setTime(13, 30)->format('Y-m-d H:i:s');

        $this->reschedule($this->hostAdmin, $this->reminderOf($this->hostAdmin), $newAt)->assertOk();

        $props = ActivityLog::findOrFail($id)->properties;
        $this->assertSame($this->utc($newAt), $props['activity_date']);
        $this->assertSame($dueBefore, $props['task']['due_date'], 'The due date stays.');

        $creator = $this->reminderOf($this->hostAdmin);
        $assignee = $this->reminderOf($this->partnerColleague);
        $this->assertSame($this->utc($newRemind), $creator->reminder_date_time->toDateTimeString());
        $this->assertSame($this->utc($newRemind), $assignee->reminder_date_time->toDateTimeString());
        $this->assertSame($this->utc($newAt), $assignee->set_date_time->toDateTimeString());
        $this->assertSame('Client asked for Friday', collect($creator->snooze_history)->last()['note']);
    }

    #[Test]
    public function the_assignee_is_told_when_the_creator_reschedules(): void
    {
        $this->storedTask();
        $newAt = now(self::TIMEZONE)->addDays(5)->setTime(14, 0)->format('Y-m-d H:i:s');

        $this->reschedule($this->hostAdmin, $this->reminderOf($this->hostAdmin), $newAt)->assertOk();

        $bell = $this->bellsTo($this->partnerColleague)->first();
        $this->assertNotNull($bell);
        $this->assertSame('activity_log.task_updated.client', $bell->typeKey);
        $body = $bell->message->toArray()['body'];
        $this->assertStringContainsString('Host Admin updated a task on client Johnathan Smithfield', $body);
        $this->assertStringContainsString('Date: ' . Carbon::parse($newAt)->format('M j, Y g:i A'), $body);
        Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $m) => $m->hasTo($this->partnerColleague->email)
            && $m->mailData['updated_by'] === 'Host Admin'
            && in_array('Date: ' . Carbon::parse($newAt)->format('M j, Y g:i A'), $m->mailData['changes'], true));
        Notification::assertNotSentTo($this->hostAdmin, DynamicNotification::class);
    }

    #[Test]
    public function the_creators_silent_row_reschedules_the_task_too_and_stays_silent(): void
    {
        $this->storedTask(['reminder_recipient' => 'assignee']);
        $silent = $this->reminderOf($this->hostAdmin);
        $this->assertFalse($silent->is_reminder);
        $newAt = now(self::TIMEZONE)->addDays(6)->setTime(9, 0)->format('Y-m-d H:i:s');
        $newRemind = now(self::TIMEZONE)->addDays(6)->setTime(8, 30)->format('Y-m-d H:i:s');

        $this->reschedule($this->hostAdmin, $silent, $newAt)->assertOk();

        $this->assertSame($this->utc($newRemind), $this->reminderOf($this->partnerColleague)->reminder_date_time->toDateTimeString());
        $this->assertFalse($this->reminderOf($this->hostAdmin)->fresh()->is_reminder, 'Only the assignee is reminded.');
    }

    #[Test]
    public function the_assignee_cannot_reschedule(): void
    {
        $id = $this->storedTask();
        $before = ActivityLog::findOrFail($id)->properties['activity_date'];
        $assignee = $this->reminderOf($this->partnerColleague);
        $assigneeAt = $assignee->reminder_date_time->toDateTimeString();

        $this->reschedule($this->partnerColleague, $assignee, now(self::TIMEZONE)->addDays(5)->format('Y-m-d H:i:s'))
            ->assertStatus(403)
            ->assertJsonPath('message', "Only the task's creator can reschedule it.");

        $this->assertSame($before, ActivityLog::findOrFail($id)->properties['activity_date']);
        $this->assertSame($assigneeAt, $assignee->fresh()->reminder_date_time->toDateTimeString());
    }

    #[Test]
    public function snooze_and_dismiss_stay_each_persons_own(): void
    {
        $this->storedTask();
        $creator = $this->reminderOf($this->hostAdmin);
        $assignee = $this->reminderOf($this->partnerColleague);
        $creatorAt = $creator->reminder_date_time->toDateTimeString();

        Passport::actingAs($this->partnerColleague, [], 'api');
        $this->postJson("/api/v1/common/reminders/{$assignee->id}/snooze", ['minutes' => 60])->assertOk();
        $this->assertSame($creatorAt, $creator->fresh()->reminder_date_time->toDateTimeString());

        $this->postJson("/api/v1/common/reminders/{$assignee->id}/dismiss")->assertOk();
        $this->assertSame(Reminder::STATUS_CANCELLED, $assignee->fresh()->status);
        $this->assertSame(Reminder::STATUS_PENDING, $creator->fresh()->status);

        Notification::assertNothingSent();
    }

    #[Test]
    public function each_reminder_says_whether_its_owner_may_reschedule_it(): void
    {
        $this->storedTask();

        Passport::actingAs($this->partnerColleague, [], 'api');
        $forAssignee = collect($this->withHeader('X-Timezone', self::TIMEZONE)
            ->postJson('/api/v1/common/reminders/fetch-reminders', [])->json('data.reminders.data'))->first();
        $this->assertFalse($forAssignee['can_reschedule']);

        Passport::actingAs($this->hostAdmin, [], 'api');
        $forCreator = collect($this->withHeader('X-Timezone', self::TIMEZONE)
            ->postJson('/api/v1/common/reminders/fetch-reminders', [])->json('data.reminders.data'))->first();
        $this->assertTrue($forCreator['can_reschedule']);
    }

    #[Test]
    public function the_assignee_is_told_when_the_creator_changes_the_due_date_or_priority(): void
    {
        $id = $this->storedTask();
        $newDue = $this->dueDate(8);

        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->updateActivity($id, $this->taskEdit(['task_due_date' => $newDue, 'task_priority' => 'low']))->assertOk();

        $body = $this->bellsTo($this->partnerColleague)->first()->message->toArray()['body'];
        $this->assertStringContainsString('Due date: ' . Carbon::parse($newDue)->format('M j, Y g:i A'), $body);
        $this->assertStringContainsString('Priority: Low', $body);
        Mail::assertQueued(TaskAssignedMail::class, 1);
    }

    #[Test]
    public function a_status_change_tells_the_other_person(): void
    {
        $id = $this->storedTask();

        Passport::actingAs($this->partnerColleague, [], 'api');
        $this->withHeader('X-Timezone', self::TIMEZONE)
            ->patchJson("/api/v1/common/activity-logs/{$id}/task-status", ['task_status' => 'in_progress'])->assertOk();

        $bell = $this->bellsTo($this->hostAdmin)->first();
        $this->assertNotNull($bell, 'The creator hears of the assignee\'s change.');
        $this->assertStringContainsString('Partner Colleague updated a task', $bell->message->toArray()['body']);
        $this->assertStringContainsString('Status: In Progress', $bell->message->toArray()['body']);
        Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $m) => $m->hasTo($this->hostAdmin->email));
        Notification::assertNotSentTo($this->partnerColleague, DynamicNotification::class);

        Notification::fake();
        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->withHeader('X-Timezone', self::TIMEZONE)
            ->patchJson("/api/v1/common/activity-logs/{$id}/task-status", ['task_status' => 'complete'])->assertOk();

        $this->assertStringContainsString('Status: Complete', $this->bellsTo($this->partnerColleague)->first()->message->toArray()['body']);
    }

    #[Test]
    public function an_edit_that_changes_none_of_those_tells_nobody(): void
    {
        $id = $this->storedTask();

        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->updateActivity($id, ['subject' => 'New wording'])->assertOk();

        Notification::assertNothingSent();
        Mail::assertNothingQueued();
    }
}
