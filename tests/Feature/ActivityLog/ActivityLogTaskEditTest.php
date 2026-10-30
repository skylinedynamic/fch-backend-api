<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * "Want to Assign" on Edit: the task's assignee, due date, priority and
 * status can be changed, the task un-assigned or assigned for the first
 * time - under the same rules as New Activity.
 */
class ActivityLogTaskEditTest extends TestCase
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

    private function storedTask(array $overrides = []): int
    {
        return $this->storeActivity($this->taskPayload($overrides))->assertCreated()->json('data.id');
    }

    #[Test]
    public function the_assignee_due_date_priority_and_status_can_be_changed(): void
    {
        $id = $this->storedTask();
        $newDue = $this->dueDate(6);

        $this->updateActivity($id, $this->taskEdit([
            'assigned_to' => $this->partnerUser->id,
            'task_due_date' => $newDue,
            'task_priority' => 'low',
            'task_status' => 'complete',
        ]))->assertOk()
            ->assertJsonPath('data.task.assigned_user.name', 'Jason Rodriguez')
            ->assertJsonPath('data.task.due_date', $newDue)
            ->assertJsonPath('data.task.priority', 'low')
            ->assertJsonPath('data.task.status', 'complete')
            ->assertJsonPath('data.task.status_label', 'Complete');

        $this->assertSame($this->partnerUser->id, ActivityLog::findOrFail($id)->properties['assigned_to']);
    }

    #[Test]
    public function turning_want_to_assign_off_unassigns_the_task(): void
    {
        $id = $this->storedTask();

        $this->updateActivity($id, ['reference' => 'Task', 'assign_task' => '0'])
            ->assertOk()
            ->assertJsonPath('data.task', null);

        $props = ActivityLog::findOrFail($id)->properties;
        $this->assertArrayNotHasKey('task', $props);
        $this->assertNull($props['assigned_to']);
    }

    #[Test]
    public function an_unassigned_task_can_be_assigned_from_edit(): void
    {
        $id = $this->storedTask(['assign_task' => '0']);

        $this->updateActivity($id, $this->taskEdit())
            ->assertOk()
            ->assertJsonPath('data.task.assigned_user.name', 'Partner Colleague');
    }

    #[Test]
    public function changing_the_reference_away_from_task_drops_the_assignment(): void
    {
        $id = $this->storedTask();

        $this->updateActivity($id, ['reference' => 'Call'])
            ->assertOk()
            ->assertJsonPath('data.task', null);
    }

    #[Test]
    public function an_edit_without_task_fields_leaves_the_task_as_it_is(): void
    {
        $id = $this->storedTask();

        $this->updateActivity($id, ['subject' => 'New wording'])
            ->assertOk()
            ->assertJsonPath('data.task.assigned_user.name', 'Partner Colleague')
            ->assertJsonPath('data.task.priority', 'high');
    }

    #[Test]
    public function the_new_assignee_must_be_on_the_records_team(): void
    {
        $id = $this->storedTask();

        foreach ([$this->hostColleague, $this->borrowerUser] as $outsider) {
            $this->updateActivity($id, $this->taskEdit(['assigned_to' => $outsider->id]))
                ->assertStatus(422)
                ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
        }

        $this->assertSame($this->partnerColleague->id, ActivityLog::findOrFail($id)->properties['task']['assigned_to']);
    }

    #[Test]
    public function on_a_partners_page_the_host_reassigns_among_its_own_staff(): void
    {
        $payload = array_diff_key($this->taskPayload([
            'partner_id' => $this->partner->id,
            'assigned_to' => $this->hostColleague->id,
        ]), ['borrower_id' => true]);
        $id = $this->storeActivity($payload)->assertCreated()->json('data.id');
        $third = $this->makeUser(\App\Models\User\User::ADMIN, ['name' => 'Casey Lane', 'partner_id' => $this->hostPartner->id]);

        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $third->id]))
            ->assertOk()
            ->assertJsonPath('data.task.assigned_user.name', 'Casey Lane');

        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $this->partnerColleague->id]))
            ->assertStatus(422);
    }

    #[Test]
    public function a_saved_due_date_that_has_passed_may_stay_but_a_new_one_may_not_be_past(): void
    {
        $id = $this->storedTask();
        $props = ActivityLog::findOrFail($id)->properties;
        $props['task']['due_date'] = $this->dueDate(-2);
        ActivityLog::whereKey($id)->update(['properties' => json_encode($props)]);

        $this->updateActivity($id, $this->taskEdit(['task_due_date' => $this->dueDate(-2), 'task_status' => 'complete']))
            ->assertOk()
            ->assertJsonPath('data.task.status', 'complete');

        $this->updateActivity($id, $this->taskEdit(['task_due_date' => $this->dueDate(-1)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.task_due_date', 'The due date cannot be in the past.');
    }

    #[Test]
    public function an_assignee_who_has_since_become_inactive_may_stay_but_none_may_be_newly_picked(): void
    {
        $id = $this->storedTask();
        $this->partnerColleague->forceFill(['status' => \App\Models\User\User::STATUS['inactive']])->save();

        $this->updateActivity($id, $this->taskEdit(['task_status' => 'complete']))
            ->assertOk()
            ->assertJsonPath('data.task.assigned_to', $this->partnerColleague->id)
            ->assertJsonPath('data.task.status', 'complete');

        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $this->partnerUser->id]))->assertOk();
        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $this->partnerColleague->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'The selected staff member is inactive and cannot be assigned this task.');
    }

    #[Test]
    public function the_task_fields_are_required_and_validated_on_edit_too(): void
    {
        $id = $this->storedTask();

        $this->updateActivity($id, $this->taskEdit([
            'assigned_to' => '',
            'task_due_date' => '',
            'task_priority' => 'urgent',
            'task_status' => '',
        ]))->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'Please select who the task is assigned to.')
            ->assertJsonPath('errors.task_due_date', 'Please select when the task is due.')
            ->assertJsonPath('errors.task_priority', 'Priority must be Low, Medium or High.')
            ->assertJsonPath('errors.task_status', 'Please select a status.');
    }

    #[Test]
    public function only_a_task_can_be_assigned_on_edit(): void
    {
        $id = $this->storedTask();

        $this->updateActivity($id, $this->taskEdit(['reference' => 'Meeting']))
            ->assertStatus(422)
            ->assertJsonPath('errors.assign_task', 'Only a Task activity can be assigned.');
    }

    #[Test]
    public function another_users_activity_cannot_be_reassigned(): void
    {
        $id = $this->storedTask();
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $this->partnerUser->id]))
            ->assertStatus(403);
    }
}
