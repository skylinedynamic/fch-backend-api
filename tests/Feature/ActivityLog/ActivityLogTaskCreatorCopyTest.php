<?php

namespace Tests\Feature\ActivityLog;

use App\Jobs\Reminder\DispatchReminderDueJob;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\Reminder;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * The creator of a Task assigned to someone else always has it in their Task
 * & Reminders list - even when only the assignee is reminded, or no reminder
 * is set. That row is silent (is_reminder false): it never pops up.
 */
class ActivityLogTaskCreatorCopyTest extends TestCase
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

    /** @return \Illuminate\Support\Collection<int, Reminder> */
    private function remindersOf(User $user)
    {
        return Reminder::where('created_by', $user->id)->where('created_by_entity_type', User::class)->get();
    }

    private function listFor(User $user): array
    {
        Passport::actingAs($user, [], 'api');

        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->postJson('/api/v1/common/reminders/fetch-reminders', [])
            ->assertOk()
            ->json('data.reminders.data');
    }

    private function dueFor(User $user): array
    {
        Passport::actingAs($user, [], 'api');

        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->getJson('/api/v1/common/reminders/due')
            ->assertOk()
            ->json('data.reminders');
    }

    #[Test]
    public function when_only_the_assignee_is_reminded_the_creator_still_has_a_silent_row(): void
    {
        $remindAt = $this->remindAt();
        $id = $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $remindAt, 'reminder_recipient' => 'assignee']))
            ->assertCreated()->json('data.id');

        $mine = $this->remindersOf($this->hostAdmin);
        $this->assertCount(1, $mine);
        $this->assertFalse($mine->first()->is_reminder, 'Listed, never popped up.');
        $this->assertSame((int) $id, (int) $mine->first()->activity_log_id);
        $this->assertSame(Carbon::parse($remindAt, self::TIMEZONE)->utc()->toDateTimeString(), $mine->first()->reminder_date_time->toDateTimeString());
        $this->assertTrue($this->remindersOf($this->partnerColleague)->first()->is_reminder);

        $props = ActivityLog::findOrFail($id)->properties;
        $this->assertNull($props['reminder_id'] ?? null, 'Not the creator\'s own reminder.');
        $this->assertSame($mine->first()->id, $props['creator_task_reminder_id']);
    }

    #[Test]
    public function with_no_reminder_set_the_creator_has_a_silent_row_on_the_due_day(): void
    {
        $due = $this->dueDate(4);
        $this->storeActivity($this->taskPayload(['task_due_date' => $due]))->assertCreated();

        $mine = $this->remindersOf($this->hostAdmin);
        $this->assertCount(1, $mine);
        $this->assertFalse($mine->first()->is_reminder);
        $this->assertSame($this->dueMorningUtc($due), $mine->first()->reminder_date_time->toDateTimeString());
    }

    #[Test]
    public function the_silent_row_is_listed_with_its_task_but_never_due(): void
    {
        $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt(), 'reminder_recipient' => 'assignee']))->assertCreated();
        $row = $this->remindersOf($this->hostAdmin)->first();
        // Its time has come
        $row->forceFill(['reminder_date_time' => now()->subMinute(), 'send_at' => now()->subMinute()])->saveQuietly();

        $listed = collect($this->listFor($this->hostAdmin))->firstWhere('id', $row->id);
        $this->assertNotNull($listed);
        $this->assertFalse($listed['is_reminder']);
        $this->assertSame('open', $listed['task']['status']);
        $this->assertTrue($listed['task_can_update_status']);

        $this->assertNull(collect($this->dueFor($this->hostAdmin))->firstWhere('id', $row->id), 'Never in the pop-up.');
    }

    #[Test]
    public function its_job_and_the_fallback_sweep_never_fire_it(): void
    {
        $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt(), 'reminder_recipient' => 'assignee']))->assertCreated();
        $row = $this->remindersOf($this->hostAdmin)->first();
        $row->forceFill(['reminder_date_time' => now()->subMinute(), 'send_at' => now()->subMinute()])->saveQuietly();
        Event::fake();

        (new DispatchReminderDueJob($row->id, $row->reminder_date_time))->handle();
        $this->artisan('task:reminder-check')->assertSuccessful();

        $this->assertSame(Reminder::STATUS_PENDING, $row->fresh()->status);
        Event::assertNotDispatched(\App\Events\ReminderDueEvent::class);
    }

    #[Test]
    public function the_creator_reminded_too_needs_no_silent_row(): void
    {
        $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt(), 'reminder_recipient' => 'both']))->assertCreated();

        $mine = $this->remindersOf($this->hostAdmin);
        $this->assertCount(1, $mine);
        $this->assertTrue($mine->first()->is_reminder);
    }

    #[Test]
    public function switching_who_is_reminded_swaps_the_silent_row_and_the_real_one(): void
    {
        $id = $this->storeActivity($this->taskPayload(['remind_me' => '1', 'remind_at' => $this->remindAt(), 'reminder_recipient' => 'assignee']))
            ->assertCreated()->json('data.id');
        $edit = fn (string $recipient) => $this->taskEdit(['remind_me' => '1', 'remind_at' => $this->remindAt(), 'reminder_recipient' => $recipient]);

        $this->updateActivity($id, $edit('both'))->assertOk();
        $mine = $this->remindersOf($this->hostAdmin);
        $this->assertCount(1, $mine);
        $this->assertTrue($mine->first()->is_reminder);
        $this->assertNull(ActivityLog::findOrFail($id)->properties['creator_task_reminder_id']);

        $this->updateActivity($id, $edit('assignee'))->assertOk();
        $mine = $this->remindersOf($this->hostAdmin);
        $this->assertCount(1, $mine);
        $this->assertFalse($mine->first()->is_reminder);
    }

    #[Test]
    public function the_silent_row_goes_with_the_assignment_and_the_activity(): void
    {
        $first = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $second = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        $this->assertCount(2, $this->remindersOf($this->hostAdmin));

        $this->updateActivity($first, ['reference' => 'Task', 'assign_task' => '0'])->assertOk();
        $this->withHeader('X-Timezone', self::TIMEZONE)->deleteJson("/api/v1/common/activity-logs/{$second}")->assertOk();

        $this->assertCount(0, $this->remindersOf($this->hostAdmin));
    }

    #[Test]
    public function the_activity_still_reports_no_reminder_of_the_creators_own(): void
    {
        $this->storeActivity($this->taskPayload())
            ->assertCreated()
            ->assertJsonPath('data.remind_me', false);
    }

    #[Test]
    public function snoozing_the_silent_row_turns_it_into_a_real_reminder(): void
    {
        $this->storeActivity($this->taskPayload())->assertCreated();
        $row = $this->remindersOf($this->hostAdmin)->first();

        $this->postJson("/api/v1/common/reminders/{$row->id}/snooze", ['minutes' => 30])->assertOk();

        $this->assertTrue($row->fresh()->is_reminder, 'Asking to be reminded later means being reminded.');
    }
}
