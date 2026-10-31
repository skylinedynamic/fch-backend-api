<?php

namespace Tests\Feature\ActivityLog;

use App\Events\ReminderUpdatedEvent;
use App\Http\Resources\FCH\Reminder\ReminderResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Common\Reminder;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * The task's status (Open / In Progress / Complete) changed from its reminder
 * - by the person who created the task or the one it is assigned to, and
 * nobody else; the rest of the activity stays as it is.
 */
class ActivityLogTaskStatusTest extends TestCase
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

    /** A Task by the host admin, assigned to the partner's colleague, with a reminder for both. */
    private function storedTask(array $overrides = []): int
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        return $this->storeActivity($this->taskPayload(array_merge([
            'remind_me' => '1',
            'remind_at' => now(self::TIMEZONE)->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
        ], $overrides)))->assertCreated()->json('data.id');
    }

    private function changeStatus(User $user, int $id, string $status)
    {
        Passport::actingAs($user, [], 'api');

        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->patchJson("/api/v1/common/activity-logs/{$id}/task-status", ['task_status' => $status]);
    }

    private function reminderOf(User $user): Reminder
    {
        return Reminder::where('created_by', $user->id)->where('created_by_entity_type', User::class)->firstOrFail();
    }

    #[Test]
    public function the_assignee_changes_the_status(): void
    {
        $id = $this->storedTask();

        $this->changeStatus($this->partnerColleague, $id, 'in_progress')
            ->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress')
            ->assertJsonPath('data.task.status_label', 'In Progress');

        $props = ActivityLog::findOrFail($id)->properties;
        $this->assertSame('in_progress', $props['task']['status']);
        $this->assertSame('high', $props['task']['priority'], 'Only the status changes.');
        $this->assertSame('Collect bank statements', $props['subject']);
    }

    #[Test]
    public function the_creator_changes_the_status(): void
    {
        $id = $this->storedTask();

        $this->changeStatus($this->hostAdmin, $id, 'complete')
            ->assertOk()
            ->assertJsonPath('data.task.status', 'complete');
    }

    #[Test]
    public function nobody_else_may(): void
    {
        $id = $this->storedTask();

        $this->changeStatus($this->hostColleague, $id, 'complete')->assertStatus(403);
        $this->changeStatus($this->partnerUser, $id, 'complete')->assertStatus(403);

        $this->assertSame('open', ActivityLog::findOrFail($id)->properties['task']['status']);
    }

    #[Test]
    public function only_an_assigned_task_has_a_status(): void
    {
        $id = $this->storedTask(['assign_task' => '0']);

        $this->changeStatus($this->hostAdmin, $id, 'complete')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This activity is not an assigned task.');
    }

    #[Test]
    public function the_status_must_be_one_of_the_three(): void
    {
        $id = $this->storedTask();

        $this->changeStatus($this->partnerColleague, $id, 'done')
            ->assertStatus(422)
            ->assertJsonPath('errors.task_status', 'Status must be Open, In Progress or Complete.');
    }

    #[Test]
    public function each_reminder_carries_the_task_and_whether_its_owner_may_change_the_status(): void
    {
        $id = $this->storedTask();

        $forAssignee = ReminderResource::forRecipient($this->reminderOf($this->partnerColleague), $this->partnerColleague)->resolve();
        $this->assertSame($id, (int) $forAssignee['activity_log_id']);
        $this->assertSame('open', $forAssignee['task']['status']);
        $this->assertSame('Open', $forAssignee['task']['status_label']);
        $this->assertSame('High', $forAssignee['task']['priority_label']);
        $this->assertSame('Partner Colleague', $forAssignee['task']['assigned_to_name']);
        $this->assertSame('Host Admin', $forAssignee['task']['assigned_by_name']);
        $this->assertTrue($forAssignee['task_can_update_status']);
        $this->assertFalse($forAssignee['activity_can_edit'], 'The assignee still cannot edit the whole activity.');

        $forCreator = ReminderResource::forRecipient($this->reminderOf($this->hostAdmin), $this->hostAdmin)->resolve();
        $this->assertTrue($forCreator['task_can_update_status']);
    }

    #[Test]
    public function a_reminder_without_an_assigned_task_has_no_task(): void
    {
        $this->storedTask(['assign_task' => '0', 'reference' => 'Call']);

        $resource = ReminderResource::forRecipient($this->reminderOf($this->hostAdmin), $this->hostAdmin)->resolve();
        $this->assertNull($resource['task']);
        $this->assertFalse($resource['task_can_update_status']);
    }

    #[Test]
    public function open_reminders_of_both_people_are_told(): void
    {
        $id = $this->storedTask();
        $creatorReminder = $this->reminderOf($this->hostAdmin);
        $assigneeReminder = $this->reminderOf($this->partnerColleague);
        Event::fake([ReminderUpdatedEvent::class]);

        $this->changeStatus($this->partnerColleague, $id, 'in_progress')->assertOk();

        Event::assertDispatched(ReminderUpdatedEvent::class, fn ($e) => $e->reminderId === $creatorReminder->id);
        Event::assertDispatched(ReminderUpdatedEvent::class, fn ($e) => $e->reminderId === $assigneeReminder->id);
    }
}
