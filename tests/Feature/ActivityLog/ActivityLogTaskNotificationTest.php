<?php

namespace Tests\Feature\ActivityLog;

use App\Mail\ActivityLog\TaskAssignedMail;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Lead\Lead;
use App\Notifications\DynamicNotification;
use App\Services\FCH\Common\Notification\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\ActivityLog\Concerns\BuildsTaskAssignmentFixtures;
use Tests\TestCase;

/**
 * The staff member a Task is assigned to hears about it - in the bell and by
 * email - whether or not the activity sets a reminder, and again only when
 * an edit hands the task to someone new.
 */
class ActivityLogTaskNotificationTest extends TestCase
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

    private function bellTo($user): ?DynamicNotification
    {
        $sent = Notification::sent($user, DynamicNotification::class);

        return $sent->first();
    }

    #[Test]
    public function the_assignee_gets_an_email_and_a_bell_notification_without_any_reminder(): void
    {
        $this->storeActivity($this->taskPayload())->assertCreated();

        Mail::assertQueued(TaskAssignedMail::class, function (TaskAssignedMail $mail) {
            return $mail->hasTo($this->partnerColleague->email)
                && $mail->mailData['assignee_name'] === 'Partner Colleague'
                && $mail->mailData['assigner_name'] === 'Host Admin'
                && $mail->mailData['record'] === 'client Johnathan Smithfield'
                && $mail->mailData['priority_label'] === 'High'
                && $mail->mailData['status_label'] === 'Open'
                && $mail->mailData['description'] === 'Ask the client for the last 3 months'
                && $mail->mailData['path'] === '/clients/' . $this->borrower->id;
        });

        $bell = $this->bellTo($this->partnerColleague);
        $this->assertNotNull($bell);
        $this->assertSame('activity_log.task_assigned.client', $bell->typeKey);

        $message = $bell->message->toArray();
        $this->assertSame('New task assigned: Collect bank statements', $message['title']);
        $this->assertSame(
            'Host Admin assigned you a task on client Johnathan Smithfield. Due '
                . \Illuminate\Support\Carbon::parse($this->dueDate())->format('M j, Y') . ', High priority, Open.',
            $message['body']
        );
        $this->assertSame('/clients/' . $this->borrower->id, $message['url']);
        $this->assertSame('reminder', $message['category']);
        $this->assertSame('ri-task-line', $message['icon']);
    }

    #[Test]
    public function nobody_else_is_notified(): void
    {
        $this->storeActivity($this->taskPayload())->assertCreated();

        Notification::assertNotSentTo([$this->hostAdmin, $this->partnerUser, $this->borrowerUser], DynamicNotification::class);
        Mail::assertQueued(TaskAssignedMail::class, 1);
    }

    #[Test]
    public function a_task_assigned_to_yourself_sends_nothing(): void
    {
        Passport::actingAs($this->partnerUser, [], 'api');

        $this->storeActivity($this->taskPayload(['assigned_to' => $this->partnerUser->id]))->assertCreated();

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    #[Test]
    public function an_unassigned_task_or_another_reference_sends_nothing(): void
    {
        $this->storeActivity($this->taskPayload(['assign_task' => '0']))->assertCreated();
        $this->storeActivity(array_diff_key($this->taskPayload(['reference' => 'Call']), array_flip([
            'assign_task', 'assigned_to', 'task_due_date', 'task_priority', 'task_status',
        ])))->assertCreated();

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_link_and_wording_follow_the_record_the_task_is_on(): void
    {
        $lead = Lead::create([
            'partner_id' => $this->partner->id,
            'company_name' => 'Lead Co',
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::PARTNER_TYPE_LEAD,
            'status' => Lead::STATUS_LEAD_COMPLETED,
        ]);
        $this->storeActivity(array_diff_key($this->taskPayload(['lead_id' => $lead->id]), ['borrower_id' => true]))->assertCreated();

        $bell = $this->bellTo($this->partnerColleague);
        $this->assertSame('activity_log.task_assigned.lead', $bell->typeKey);
        $this->assertSame('/leads/' . $lead->id, $bell->message->toArray()['url']);
        $this->assertStringContainsString('on lead Lead Co.', $bell->message->toArray()['body']);

        $this->storeActivity(array_diff_key($this->taskPayload([
            'partner_id' => $this->partner->id,
            'assigned_to' => $this->hostColleague->id,
        ]), ['borrower_id' => true]))->assertCreated();

        $bell = $this->bellTo($this->hostColleague);
        $this->assertSame('activity_log.task_assigned.partner', $bell->typeKey);
        $this->assertSame('/partners/' . $this->partner->id, $bell->message->toArray()['url']);
        $this->assertStringContainsString('on partner Four Corner Funding.', $bell->message->toArray()['body']);
    }

    #[Test]
    public function every_record_type_has_its_own_notification_type(): void
    {
        foreach (['partner', 'client', 'lead', 'funding_application', 'funding_request'] as $record) {
            // The keys hold dots, so they are read off the array, not by config() path
            $type = config('notifications.types')["activity_log.task_assigned.{$record}"] ?? null;
            $this->assertNotNull($type, $record);
            $this->assertSame('reminder', $type['category']);
            $this->assertSame(['database', 'broadcast', 'mail'], $type['channels']);
        }

        $this->assertSame('/intake-review/funding-request/{{id}}', config('notifications.types')['activity_log.task_assigned.funding_request']['url']);
        $this->assertSame('/intake-review/funding-application/{{id}}', config('notifications.types')['activity_log.task_assigned.funding_application']['url']);
    }

    #[Test]
    public function an_edit_that_hands_the_task_to_someone_new_notifies_only_them(): void
    {
        $id = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        Mail::fake();
        Notification::fake();

        $this->updateActivity($id, $this->taskEdit(['assigned_to' => $this->partnerUser->id]))->assertOk();

        Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail) => $mail->hasTo($this->partnerUser->email));
        Mail::assertQueued(TaskAssignedMail::class, 1);
        Notification::assertSentTo($this->partnerUser, DynamicNotification::class);
        Notification::assertNotSentTo($this->partnerColleague, DynamicNotification::class);
    }

    #[Test]
    public function an_edit_that_keeps_the_assignee_sends_nothing(): void
    {
        $id = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');
        Mail::fake();
        Notification::fake();

        $this->updateActivity($id, ['subject' => 'New wording'])->assertOk();
        $this->updateActivity($id, $this->taskEdit(['task_priority' => 'low', 'task_status' => 'complete']))->assertOk();
        $this->updateActivity($id, ['reference' => 'Task', 'assign_task' => '0'])->assertOk();

        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    #[Test]
    public function assigning_from_edit_notifies_the_assignee(): void
    {
        $id = $this->storeActivity($this->taskPayload(['assign_task' => '0']))->assertCreated()->json('data.id');

        $this->updateActivity($id, $this->taskEdit())->assertOk();

        Notification::assertSentTo($this->partnerColleague, DynamicNotification::class);
        Mail::assertQueued(TaskAssignedMail::class, 1);
    }

    #[Test]
    public function a_notification_failure_never_fails_saving_the_task(): void
    {
        $this->mock(Notifier::class)->shouldReceive('send')->andThrow(new \RuntimeException('mail server down'));

        $id = $this->storeActivity($this->taskPayload())->assertCreated()->json('data.id');

        $this->assertNotNull(ActivityLog::find($id)?->properties['task']);
    }
}
