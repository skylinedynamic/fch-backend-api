<?php

namespace Tests\Feature\ActivityLog;

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
 * "Who gets the reminder" on an assigned Task that sets a reminder: the
 * author (creator), the assignee, or both (the default). It decides only who
 * gets the reminder the activity sets - with no reminder set, the assignee is
 * still reminded on the due day.
 */
class ActivityLogTaskReminderRecipientTest extends TestCase
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

    private function remindAt(): string
    {
        return now(self::TIMEZONE)->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s');
    }

    private function withReminder(?string $recipient, array $overrides = []): array
    {
        return $this->taskPayload(array_merge(
            ['remind_me' => '1', 'remind_at' => $this->remindAt()],
            $recipient !== null ? ['reminder_recipient' => $recipient] : [],
            $overrides
        ));
    }

    private function reminderOf(User $user): ?Reminder
    {
        return Reminder::where('created_by', $user->id)->where('created_by_entity_type', User::class)->first();
    }

    #[Test]
    public function both_is_the_default_and_each_gets_their_own(): void
    {
        $this->storeActivity($this->withReminder(null))
            ->assertCreated()
            ->assertJsonPath('data.reminder_recipient', ActivityLog::REMINDER_RECIPIENT_BOTH);

        $this->assertNotNull($this->reminderOf($this->hostAdmin));
        $this->assertNotNull($this->reminderOf($this->partnerColleague));
    }

    #[Test]
    public function the_assignee_only_gets_it_and_the_creator_none(): void
    {
        $remindAt = $this->remindAt();
        $this->storeActivity($this->withReminder('assignee', ['remind_at' => $remindAt]))
            ->assertCreated()
            ->assertJsonPath('data.reminder_recipient', 'assignee')
            ->assertJsonPath('data.remind_me', true)
            ->assertJsonPath('data.remind_at', $remindAt);

        $this->assertFalse($this->reminderOf($this->hostAdmin)->is_reminder, 'The creator only lists it - no pop-up.');
        $this->assertSame(
            Carbon::parse($remindAt, self::TIMEZONE)->utc()->toDateTimeString(),
            $this->reminderOf($this->partnerColleague)->reminder_date_time->toDateTimeString()
        );
    }

    #[Test]
    public function the_creator_only_gets_it_and_the_assignee_none(): void
    {
        $this->storeActivity($this->withReminder('creator'))
            ->assertCreated()
            ->assertJsonPath('data.reminder_recipient', 'creator');

        $this->assertNotNull($this->reminderOf($this->hostAdmin));
        $this->assertNull($this->reminderOf($this->partnerColleague));
    }

    #[Test]
    public function without_a_reminder_set_the_assignee_is_still_reminded_on_the_due_day(): void
    {
        $this->storeActivity($this->taskPayload(['reminder_recipient' => 'creator']))->assertCreated();

        $this->assertFalse($this->reminderOf($this->hostAdmin)->is_reminder);
        $this->assertNotNull($this->reminderOf($this->partnerColleague));
    }

    #[Test]
    public function on_a_task_assigned_to_yourself_you_get_it_whatever_was_picked(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');
        $this->storeActivity($this->withReminder('assignee', ['assigned_to' => $this->partnerUser->id]))
            ->assertCreated()
            ->assertJsonPath('data.reminder_recipient', 'creator');

        $this->assertSame(1, Reminder::count());
        $this->assertNotNull($this->reminderOf($this->partnerUser));
    }

    #[Test]
    public function an_edit_moves_the_reminder_between_creator_and_assignee(): void
    {
        $id = $this->storeActivity($this->withReminder('both'))->assertCreated()->json('data.id');
        $edit = fn (string $recipient) => $this->taskEdit([
            'remind_me' => '1',
            'remind_at' => $this->remindAt(),
            'reminder_recipient' => $recipient,
        ]);

        $this->updateActivity($id, $edit('assignee'))->assertOk()->assertJsonPath('data.reminder_recipient', 'assignee');
        $this->assertFalse($this->reminderOf($this->hostAdmin)->is_reminder);
        $this->assertNotNull($this->reminderOf($this->partnerColleague));
        $this->assertNull(ActivityLog::findOrFail($id)->properties['reminder_id']);

        $this->updateActivity($id, $edit('creator'))->assertOk()->assertJsonPath('data.reminder_recipient', 'creator');
        $this->assertTrue($this->reminderOf($this->hostAdmin)->is_reminder);
        $this->assertNull($this->reminderOf($this->partnerColleague));

        $this->updateActivity($id, $edit('both'))->assertOk();
        $this->assertSame(2, Reminder::count());
    }

    #[Test]
    public function it_must_be_one_of_the_three(): void
    {
        $this->storeActivity($this->withReminder('everyone'))
            ->assertStatus(422)
            ->assertJsonPath('errors.reminder_recipient', 'Please choose who gets the reminder.');
    }

    #[Test]
    public function the_assignees_reminder_is_not_shown_as_the_creators(): void
    {
        // No reminder set: only the assignee's due-day one exists.
        $this->storeActivity($this->taskPayload())
            ->assertCreated()
            ->assertJsonPath('data.remind_me', false);
    }
}
