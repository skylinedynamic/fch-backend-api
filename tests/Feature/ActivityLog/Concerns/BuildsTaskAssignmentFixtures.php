<?php

namespace Tests\Feature\ActivityLog\Concerns;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The people and records "Want to Assign" works with: the host and a partner,
 * each with two staff members, and a client of the partner (with a login).
 */
trait BuildsTaskAssignmentFixtures
{
    protected const TIMEZONE = 'America/New_York';

    protected Partner $hostPartner;
    protected Partner $partner;
    protected Borrower $borrower;

    protected User $hostAdmin;
    protected User $hostColleague;
    protected User $partnerUser;
    protected User $partnerColleague;
    protected User $borrowerUser;

    protected function buildTaskAssignmentFixtures(): void
    {
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

    protected function makeUser(string $userType, array $attributes = []): User
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

    /** A calendar day $days from today, in the viewer's timezone. */
    protected function dueDate(int $days = 3): string
    {
        return now(self::TIMEZONE)->addDays($days)->format('Y-m-d');
    }

    /** A Task on the partner's client, assigned to one of the partner's staff. */
    protected function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'log_type' => 'activity',
            'borrower_id' => $this->borrower->id,
            'reference' => 'Task',
            'subject' => 'Collect bank statements',
            'description' => '<p>Ask the client for the last 3 months</p>',
            'activity_date' => now(self::TIMEZONE)->addDay()->format('Y-m-d H:i:s'),
            'visibility' => 'private',
            'assign_task' => '1',
            'assigned_to' => $this->partnerColleague->id,
            'task_due_date' => $this->dueDate(),
            'task_priority' => 'high',
            'task_status' => 'open',
        ], $overrides);
    }

    /** The task fields an Edit sends with "Want to Assign" on. */
    protected function taskEdit(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'Task',
            'assign_task' => '1',
            'assigned_to' => $this->partnerColleague->id,
            'task_due_date' => $this->dueDate(),
            'task_priority' => 'high',
            'task_status' => 'open',
        ], $overrides);
    }

    protected function storeActivity(array $payload): TestResponse
    {
        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->postJson('/api/v1/common/activity-logs', $payload);
    }

    protected function updateActivity(int $id, array $payload): TestResponse
    {
        return $this->withHeader('X-Timezone', self::TIMEZONE)
            ->putJson("/api/v1/common/activity-logs/{$id}", $payload);
    }

    /** "09:00 on the due date" in the viewer's timezone, as the UTC value stored. */
    protected function dueMorningUtc(string $dueDate): string
    {
        return \Illuminate\Support\Carbon::parse("{$dueDate} 09:00:00", self::TIMEZONE)->utc()->toDateTimeString();
    }
}
