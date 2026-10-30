<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Lead\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * "Want to Assign" on New Activity (Partners, Leads, Clients, Funding
 * Application / Request › Activity log):
 *
 *   - only a Task can be assigned
 *   - once assigned, Assigned To, Due Date, Priority and Status are required
 *   - the due date is a calendar day, today or later, stored as picked
 *   - the assignee comes from the team the Assigned To list offered: for the
 *     host, the staff of the partner that owns the record (its own staff on a
 *     Partner's page); for anyone else, their own team
 *   - only an active staff member can be picked; the list says who is active
 */
class ActivityLogTaskAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsTaskAssignmentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTaskAssignmentFixtures();

        // Notifications and reminder jobs have tests of their own.
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    #[Test]
    public function a_host_assigns_a_task_on_a_client_to_the_clients_partner_staff(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $due = $this->dueDate();

        $response = $this->storeActivity($this->taskPayload(['task_due_date' => $due]))->assertCreated();

        $response->assertJsonPath('data.task.assigned_to', $this->partnerColleague->id)
            ->assertJsonPath('data.task.assigned_user.name', 'Partner Colleague')
            ->assertJsonPath('data.task.due_date', $due)
            ->assertJsonPath('data.task.priority', 'high')
            ->assertJsonPath('data.task.priority_label', 'High')
            ->assertJsonPath('data.task.status', 'open')
            ->assertJsonPath('data.task.status_label', 'Open');

        $log = ActivityLog::findOrFail($response->json('data.id'));
        $this->assertSame($due, $log->properties['task']['due_date'], 'A calendar day, stored as picked.');
        $this->assertSame($this->partnerColleague->id, $log->properties['assigned_to']);
    }

    #[Test]
    public function on_a_client_the_host_cannot_pick_its_own_staff_or_the_client(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        foreach ([$this->hostColleague, $this->borrowerUser] as $outsider) {
            $this->storeActivity($this->taskPayload(['assigned_to' => $outsider->id]))
                ->assertStatus(422)
                ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
        }

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function on_a_lead_the_host_assigns_to_the_lead_partners_staff(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $lead = Lead::create([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::PARTNER_TYPE_LEAD,
            'status' => Lead::STATUS_LEAD_COMPLETED,
        ]);
        $payload = array_diff_key($this->taskPayload(['lead_id' => $lead->id]), ['borrower_id' => true]);

        $this->storeActivity($payload)->assertCreated()
            ->assertJsonPath('data.task.assigned_user.name', 'Partner Colleague');

        $this->storeActivity(array_merge($payload, ['assigned_to' => $this->hostColleague->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
    }

    #[Test]
    public function on_a_partners_page_the_host_assigns_to_its_own_staff(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $payload = array_diff_key($this->taskPayload(['partner_id' => $this->partner->id]), ['borrower_id' => true]);

        $this->storeActivity(array_merge($payload, ['assigned_to' => $this->hostColleague->id]))
            ->assertCreated()
            ->assertJsonPath('data.task.assigned_user.name', 'Avery Staff');

        $this->storeActivity(array_merge($payload, ['assigned_to' => $this->partnerColleague->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
    }

    #[Test]
    public function a_partner_assigns_only_within_their_own_team(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->storeActivity($this->taskPayload([
            'assigned_to' => $this->partnerColleague->id,
            'task_priority' => 'low',
            'task_status' => 'in_progress',
        ]))->assertCreated()
            ->assertJsonPath('data.task.priority', 'low')
            ->assertJsonPath('data.task.status', 'in_progress')
            ->assertJsonPath('data.task.status_label', 'In Progress');

        $this->storeActivity($this->taskPayload(['assigned_to' => $this->hostColleague->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
    }

    #[Test]
    public function the_four_task_fields_are_required_once_assigning(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->storeActivity($this->taskPayload([
            'assigned_to' => '',
            'task_due_date' => '',
            'task_priority' => '',
            'task_status' => '',
        ]))->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'Please select who the task is assigned to.')
            ->assertJsonPath('errors.task_due_date', 'Please select when the task is due.')
            ->assertJsonPath('errors.task_priority', 'Please select a priority.')
            ->assertJsonPath('errors.task_status', 'Please select a status.');
    }

    #[Test]
    public function priority_and_status_must_be_one_of_the_offered_options(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->storeActivity($this->taskPayload(['task_priority' => 'urgent', 'task_status' => 'done']))
            ->assertStatus(422)
            ->assertJsonPath('errors.task_priority', 'Priority must be Low, Medium or High.')
            ->assertJsonPath('errors.task_status', 'Status must be Open, In Progress or Complete.');
    }

    #[Test]
    public function the_due_date_is_a_date_only(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->storeActivity($this->taskPayload(['task_due_date' => $this->dueDate() . ' 15:30:00']))
            ->assertStatus(422)
            ->assertJsonPath('errors.task_due_date', 'The due date is not a valid date.');
    }

    #[Test]
    public function the_due_date_may_be_today_but_not_earlier(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->storeActivity($this->taskPayload(['task_due_date' => $this->dueDate(0)]))->assertCreated();

        $this->storeActivity($this->taskPayload(['task_due_date' => $this->dueDate(-1)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.task_due_date', 'The due date cannot be in the past.');
    }

    #[Test]
    public function only_a_task_can_be_assigned(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->storeActivity($this->taskPayload(['reference' => 'Call']))
            ->assertStatus(422)
            ->assertJsonPath('errors.assign_task', 'Only a Task activity can be assigned.');
    }

    #[Test]
    public function an_unassigned_task_has_no_task_details(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $response = $this->storeActivity([
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Plain task',
            'description' => 'Nobody assigned',
            'activity_date' => now(self::TIMEZONE)->addDay()->format('Y-m-d H:i:s'),
            'visibility' => 'private',
            'assign_task' => '0',
        ])->assertCreated();

        $response->assertJsonPath('data.task', null);
        $this->assertArrayNotHasKey('task', ActivityLog::findOrFail($response->json('data.id'))->properties);
    }

    #[Test]
    public function an_inactive_staff_member_cannot_be_assigned(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->partnerColleague->forceFill(['status' => \App\Models\User\User::STATUS['inactive']])->save();

        $this->storeActivity($this->taskPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'The selected staff member is inactive and cannot be assigned this task.');

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function the_assign_list_asked_for_active_staff_leaves_out_inactive_ones_unless_kept(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $this->partnerColleague->forceFill(['status' => \App\Models\User\User::STATUS['inactive']])->save();

        $names = fn (array $payload) => collect($this->postJson('/api/v1/common/get-all-users', $payload)
            ->assertOk()
            ->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Jason Rodriguez'], $names(['partner_id' => $this->partner->id, 'active_only' => true]));
        $this->assertSame(
            ['Jason Rodriguez', 'Partner Colleague'],
            $names(['partner_id' => $this->partner->id, 'active_only' => true, 'keep_ids' => [$this->partnerColleague->id]]),
            'The one already assigned stays, so the field still shows them.'
        );
        $this->assertSame(
            ['Jason Rodriguez', 'Partner Colleague'],
            $names(['partner_id' => $this->partner->id]),
            'Without active_only (list filters) everyone is listed.'
        );
    }

    #[Test]
    public function kept_ids_never_bring_in_someone_outside_the_team(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $ids = collect($this->postJson('/api/v1/common/get-all-users', [
            'partner_id' => $this->partner->id,
            'active_only' => true,
            'keep_ids' => [$this->hostColleague->id, $this->borrowerUser->id],
        ])->assertOk()->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($this->hostColleague->id));
        $this->assertFalse($ids->contains($this->borrowerUser->id));
    }
}
