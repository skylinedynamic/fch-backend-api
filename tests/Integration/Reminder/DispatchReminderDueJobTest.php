<?php

namespace Tests\Integration\Reminder;

use App\Console\Commands\TaskReminderCheck;
use App\Events\ReminderDueEvent;
use App\Jobs\Reminder\DispatchReminderDueJob;
use App\Models\Common\Reminder;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The job queued for a reminder's exact due time, and the five-minute sweep
 * that catches what it missed: between them a due reminder is pushed exactly
 * once, and one that was snoozed, dismissed or deleted is not pushed at all.
 */
#[CoversClass(DispatchReminderDueJob::class)]
#[CoversClass(TaskReminderCheck::class)]
class DispatchReminderDueJobTest extends TestCase
{
    use RefreshDatabase;

    private int $partnerId;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([ReminderDueEvent::class]);
        Carbon::setTestNow('2026-10-05 08:30:00');
        $this->partnerId = DB::table('partners')->insertGetId(['full_name' => 'Pat Partner', 'company_name' => 'Partner Co', 'email' => 'partner@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->owner = User::findOrFail(DB::table('users')->insertGetId([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function reminder(array $attributes = []): Reminder
    {
        return Reminder::create(array_merge([
            'entity_id' => $this->partnerId,
            'entity_type' => Partner::class,
            'created_by' => $this->owner->id,
            'created_by_entity_type' => User::class,
            'reference' => 'Call',
            'summary' => 'Call about the file',
            'set_date_time' => '2026-10-05 09:00:00',
            'reminder_date_time' => '2026-10-05 08:00:00',
            'send_at' => '2026-10-05 08:00:00',
            'status' => Reminder::STATUS_PENDING,
        ], $attributes));
    }

    private function fire(Reminder $reminder, string $expectedDueAt = '2026-10-05 08:00:00'): void
    {
        (new DispatchReminderDueJob($reminder->id, Carbon::parse($expectedDueAt)))->handle();
    }

    #[Test]
    public function a_due_reminder_is_marked_sent_and_pushed(): void
    {
        $reminder = $this->reminder();

        $this->fire($reminder);

        $this->assertSame(Reminder::STATUS_SENT, $reminder->fresh()->status);
        Event::assertDispatchedTimes(ReminderDueEvent::class, 1);
    }

    #[Test]
    public function a_reminder_snoozed_past_the_jobs_time_waits_for_its_own_job(): void
    {
        $reminder = $this->reminder(['reminder_date_time' => '2026-10-05 10:00:00', 'send_at' => '2026-10-05 10:00:00']);

        $this->fire($reminder);

        $this->assertSame(Reminder::STATUS_PENDING, $reminder->fresh()->status);
        Event::assertNotDispatched(ReminderDueEvent::class);
    }

    #[Test]
    public function a_reminder_moved_earlier_still_fires_when_a_later_job_runs(): void
    {
        $reminder = $this->reminder(['reminder_date_time' => '2026-10-05 07:45:00']);

        $this->fire($reminder, '2026-10-05 08:15:00');

        Event::assertDispatchedTimes(ReminderDueEvent::class, 1);
    }

    #[Test]
    public function a_dismissed_sent_or_deleted_reminder_is_not_pushed(): void
    {
        $this->fire($this->reminder(['status' => Reminder::STATUS_CANCELLED]));
        $this->fire($this->reminder(['status' => Reminder::STATUS_SENT]));
        $this->fire($this->reminder(['status' => Reminder::STATUS_COMPLETED]));
        $deleted = $this->reminder();
        $deleted->delete();
        $this->fire($deleted);

        Event::assertNotDispatched(ReminderDueEvent::class);
    }

    #[Test]
    public function a_reminder_whose_creator_is_gone_is_not_pushed(): void
    {
        $orphan = $this->reminder(['created_by' => 999999]);

        $this->fire($orphan);
        $this->artisan('task:reminder-check')->assertSuccessful();

        Event::assertNotDispatched(ReminderDueEvent::class);
    }

    #[Test]
    public function the_job_and_the_sweep_together_push_a_reminder_once(): void
    {
        $sweptFirst = $this->reminder();
        $jobFirst = $this->reminder();

        $this->fire($jobFirst);
        $this->artisan('task:reminder-check')->assertSuccessful();
        $this->fire($sweptFirst);
        $this->artisan('task:reminder-check')->assertSuccessful();

        Event::assertDispatchedTimes(ReminderDueEvent::class, 2);
        $this->assertSame([Reminder::STATUS_SENT], Reminder::pluck('status')->unique()->values()->all());
    }

    /**
     * Defect (O45): the sweep marked a reminder sent with a plain save of what
     * it had read, so a reminder the job claimed in the same instant - after the
     * sweep read it as pending - was pushed a second time. The sweep now claims
     * it the way the job does, and only pushes what it actually claimed.
     */
    #[Test]
    public function the_sweep_does_not_push_a_reminder_the_job_claimed_after_it_was_read(): void
    {
        $reminder = $this->reminder();
        // The job wins the race: by the time the sweep acts on what it read,
        // the row is already sent.
        Reminder::retrieved(function (Reminder $read) {
            DB::table('reminders')->where('id', $read->id)->update(['status' => Reminder::STATUS_SENT]);
        });

        $this->artisan('task:reminder-check')->assertSuccessful();

        Event::assertNotDispatched(ReminderDueEvent::class);
        $this->assertSame(Reminder::STATUS_SENT, $reminder->fresh()->status);
    }
}
