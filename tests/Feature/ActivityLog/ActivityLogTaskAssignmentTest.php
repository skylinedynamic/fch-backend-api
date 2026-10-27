<?php

namespace Tests\Feature\ActivityLog;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Want to Assign" on a manual Task activity (Partners, Leads, Clients and
 * Funding Application › Activity log › New Activity):
 *
 *   - only a Task can be assigned
 *   - once assigned, Assigned To, Due Date / Time, Priority and Status are required
 *   - the assignee must be on the caller's own team (the list the form offers)
 *   - the due date is a wall-clock value in the viewer's timezone, stored in UTC
 *     and kept separate from the activity date
 */
class ActivityLogTaskAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/New_York';

    private Partner $hostPartner;
    private Partner $partner;
    private Borrower $borrower;

    private User $hostAdmin;
    private User $hostColleague;
    private User $partnerUser;
    private User $partnerColleague;
    private User $borrowerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostPartner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Johnathan',
            'last_name' => 'Smithfield',
            'email' => 'borrower-' . uniqid() . '@example.test',
        ]);

        $this->hostAdmin = $this->makeUser(User::ADMIN, ['name' => 'Host Admin', 'partner_id' => $this->hostPartner->id]);
        $this->hostColleague = $this->makeUser(User::ADMIN, ['name' => 'Avery Staff', 'partner_id' => $this->hostPartner->id]);
        $this->partnerUser = $this->makeUser(User::PARTNER, ['name' => 'Jason Rodriguez', 'partner_id' => $this->partner->id]);
        $this->partnerColleague = $this->makeUser(User::PARTNER, ['name' => 'Partner Colleague', 'partner_id' => $this->partner->id]);
        $this->borrowerUser = $this->makeUser(User::BORROWER, [
            'name' => 'Johnathan Smithfield',
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'partner_id' => $this->partner->id,
        ]);
    }

    private function makeUser(string $userType, array $attributes = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return User::findOrFail($id);
    }

    private function dueAt(): string
    {
        return now(self::TIMEZONE)->addDays(3)->setTime(15, 30)->format('Y-m-d H:i:s');
    }

    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Collect bank statements',
            'description' => 'Ask the client for the last 3 months',
            'activity_date' => now(self::TIMEZONE)->addDay()->format('Y-m-d H:i:s'),
            'visibility' => 'internal',
            'assign_task' => '1',
            'assigned_to' => $this->hostColleague->id,
            'task_due_at' => $this->dueAt(),
            'task_priority' => 'high',
            'task_status' => 'open',
        ], $overrides);
    }

    private function store(array $payload)
    {
        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->postJson('/api/v1/common/activity-logs', $payload);
    }

    #[Test]
    public function a_host_can_assign_a_task_to_a_staff_member_with_due_date_priority_and_status(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');
        $dueAt = $this->dueAt();

        $response = $this->store($this->taskPayload(['task_due_at' => $dueAt]))->assertCreated();

        $response->assertJsonPath('data.task.assigned_to', $this->hostColleague->id)
            ->assertJsonPath('data.task.assigned_user.name', 'Avery Staff')
            ->assertJsonPath('data.task.priority', 'high')
            ->assertJsonPath('data.task.priority_label', 'High')
            ->assertJsonPath('data.task.status', 'open')
            ->assertJsonPath('data.task.status_label', 'Open')
            // Shown back in the viewer's own timezone.
            ->assertJsonPath('data.task.due_at', $dueAt);

        $log = ActivityLog::findOrFail($response->json('data.id'));
        $this->assertSame(
            Carbon::parse($dueAt, self::TIMEZONE)->utc()->format('Y-m-d H:i:s'),
            $log->properties['task']['due_at'],
            'The due date is stored in UTC.'
        );
        $this->assertNotSame($log->properties['activity_date'], $log->properties['task']['due_at']);
        $this->assertSame($this->hostColleague->id, $log->properties['assigned_to']);
    }

    #[Test]
    public function a_partner_can_assign_a_task_to_someone_on_their_own_team(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->store($this->taskPayload([
            'visibility' => 'partner',
            'assigned_to' => $this->partnerColleague->id,
            'task_priority' => 'low',
            'task_status' => 'in_progress',
        ]))->assertCreated()
            ->assertJsonPath('data.task.assigned_user.name', 'Partner Colleague')
            ->assertJsonPath('data.task.priority', 'low')
            ->assertJsonPath('data.task.status', 'in_progress')
            ->assertJsonPath('data.task.status_label', 'In Progress');
    }

    #[Test]
    public function the_four_task_fields_are_required_once_assigning(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->store($this->taskPayload([
            'assigned_to' => '',
            'task_due_at' => '',
            'task_priority' => '',
            'task_status' => '',
        ]))->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'Please select who the task is assigned to.')
            ->assertJsonPath('errors.task_due_at', 'Please select when the task is due.')
            ->assertJsonPath('errors.task_priority', 'Please select a priority.')
            ->assertJsonPath('errors.task_status', 'Please select a status.');
    }

    #[Test]
    public function priority_and_status_must_be_one_of_the_offered_options(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->store($this->taskPayload(['task_priority' => 'urgent', 'task_status' => 'done']))
            ->assertStatus(422)
            ->assertJsonPath('errors.task_priority', 'Priority must be Low, Medium or High.')
            ->assertJsonPath('errors.task_status', 'Status must be Open, In Progress or Complete.');
    }

    #[Test]
    public function the_assignee_must_be_on_the_callers_own_team(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        foreach ([$this->partnerUser, $this->borrowerUser] as $outsider) {
            $this->store($this->taskPayload(['assigned_to' => $outsider->id]))
                ->assertStatus(422)
                ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
        }

        $this->assertSame(0, ActivityLog::count());
    }

    #[Test]
    public function a_partner_cannot_assign_a_task_to_host_staff(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->store($this->taskPayload(['visibility' => 'partner', 'assigned_to' => $this->hostColleague->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_to', 'The selected staff member cannot be assigned this task.');
    }

    #[Test]
    public function the_due_date_must_be_in_the_future(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->store($this->taskPayload([
            'task_due_at' => now(self::TIMEZONE)->subHour()->format('Y-m-d H:i:s'),
        ]))->assertStatus(422)
            ->assertJsonPath('errors.task_due_at', 'The due date must be in the future.');
    }

    #[Test]
    public function only_a_task_can_be_assigned(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $this->store($this->taskPayload(['reference' => 'Call']))
            ->assertStatus(422)
            ->assertJsonPath('errors.assign_task', 'Only a Task activity can be assigned.');
    }

    #[Test]
    public function an_unassigned_task_has_no_task_details(): void
    {
        Passport::actingAs($this->hostAdmin, [], 'api');

        $response = $this->store([
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Plain task',
            'description' => 'Nobody assigned',
            'activity_date' => now(self::TIMEZONE)->addDay()->format('Y-m-d H:i:s'),
            'visibility' => 'internal',
            'assign_task' => '0',
        ])->assertCreated();

        $response->assertJsonPath('data.task', null);
        $this->assertArrayNotHasKey('task', ActivityLog::findOrFail($response->json('data.id'))->properties);
    }
}
