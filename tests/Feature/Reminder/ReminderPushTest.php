<?php

namespace Tests\Feature\Reminder;

use App\Events\ReminderDueEvent;
use App\Events\ReminderUpdatedEvent;
use App\Jobs\Reminder\DispatchReminderDueJob;
use App\Models\Common\Reminder;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin panel fetches due reminders once, on page load, and from then on
 * learns about every change over Reverb: a reminder coming due, and a shown
 * reminder being dismissed, snoozed, edited or deleted. Each push carries the
 * reminder exactly as GET /reminders/due would return it to that user - in
 * their own timezone - so the browser never has to call the API to show it.
 */
class ReminderPushTest extends TestCase
{
    use RefreshDatabase;

    private int $partnerId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-05 08:30:00');

        $this->partnerId = DB::table('partners')->insertGetId([
            'full_name' => 'Pat Partner',
            'company_name' => 'Partner Co',
            'email' => 'partner@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = $this->makeUser('owner@example.test', ['timezone' => 'Asia/Dhaka']);
        Passport::actingAs($this->user, [], 'api');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $email, array $attributes = []): User
    {
        return User::findOrFail(DB::table('users')->insertGetId(array_merge([
            'name' => 'Owner User',
            'email' => $email,
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $this->partnerId,
            'partner_id' => $this->partnerId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes)));
    }

    /** A reminder owned by $this->user, alerting at 08:00 UTC for a 09:00 UTC call. */
    private function makeReminder(array $attributes = []): Reminder
    {
        return Reminder::create(array_merge([
            'entity_id' => $this->partnerId,
            'entity_type' => Partner::class,
            'created_by' => $this->user->id,
            'created_by_entity_type' => User::class,
            'reference' => 'Call',
            'summary' => 'Call about the file',
            'set_date_time' => '2026-10-05 09:00:00',
            'reminder_date_time' => '2026-10-05 08:00:00',
            'send_at' => '2026-10-05 08:00:00',
            'status' => Reminder::STATUS_SENT,
        ], $attributes));
    }

    private function channelOf($event): string
    {
        return collect($event->broadcastOn())->map->name->join(',');
    }

    #[Test]
    public function a_reminder_coming_due_is_pushed_to_its_owner_rendered_in_their_timezone(): void
    {
        $reminder = $this->makeReminder(['status' => Reminder::STATUS_PENDING]);
        Event::fake([ReminderDueEvent::class, ReminderUpdatedEvent::class]);

        (new DispatchReminderDueJob($reminder->id, now()))->handle();

        Event::assertDispatched(ReminderDueEvent::class, function (ReminderDueEvent $event) use ($reminder) {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'reminder.due'
                && $this->channelOf($event) === "private-App.Models.User.{$this->user->id}"
                && $payload['reminder']['id'] === $reminder->id
                && $payload['reminder']['status'] === Reminder::STATUS_SENT
                // 08:00 / 09:00 UTC in Dhaka (UTC+6), the same as /due would show it.
                && $payload['reminder']['display']['reminder_time'] === '2:00 PM'
                && $payload['reminder']['display']['activity_time'] === '3:00 PM'
                && array_key_exists('client', $payload['reminder'])
                && array_key_exists('activity_can_edit', $payload['reminder']);
        });
        // The job claims the row with a plain query, so only the due push goes out.
        Event::assertNotDispatched(ReminderUpdatedEvent::class);
    }

    #[Test]
    public function a_user_with_no_known_timezone_gets_the_app_timezone(): void
    {
        $this->user->forceFill(['timezone' => null])->saveQuietly();
        $reminder = $this->makeReminder(['status' => Reminder::STATUS_PENDING]);
        Event::fake([ReminderDueEvent::class]);

        (new DispatchReminderDueJob($reminder->id, now()))->handle();

        $expected = Carbon::parse('2026-10-05 08:00:00', 'UTC')->setTimezone(config('app.timezone'))->format('g:i A');
        Event::assertDispatched(ReminderDueEvent::class, fn ($event) => $event->broadcastWith()['reminder']['display']['reminder_time'] === $expected);
    }

    #[Test]
    public function a_legacy_reminder_owned_by_an_entity_is_pushed_to_each_of_its_users(): void
    {
        $second = $this->makeUser('second@example.test', ['timezone' => 'America/New_York']);
        $reminder = $this->makeReminder([
            'status' => Reminder::STATUS_PENDING,
            'created_by' => $this->partnerId,
            'created_by_entity_type' => Partner::class,
        ]);
        Event::fake([ReminderDueEvent::class]);

        (new DispatchReminderDueJob($reminder->id, now()))->handle();

        Event::assertDispatchedTimes(ReminderDueEvent::class, 2);
        Event::assertDispatched(ReminderDueEvent::class, fn ($event) => $this->channelOf($event) === "private-App.Models.User.{$second->id}"
            // 08:00 UTC in New York (UTC-4 in October).
            && $event->broadcastWith()['reminder']['display']['reminder_time'] === '4:00 AM');
    }

    #[Test]
    public function the_fallback_sweep_pushes_a_missed_reminder_once(): void
    {
        $this->makeReminder(['status' => Reminder::STATUS_PENDING]);
        Event::fake([ReminderDueEvent::class, ReminderUpdatedEvent::class]);

        Artisan::call('task:reminder-check');

        Event::assertDispatchedTimes(ReminderDueEvent::class, 1);
        Event::assertDispatched(ReminderDueEvent::class, fn ($event) => $event->broadcastWith()['reminder']['status'] === Reminder::STATUS_SENT);
        Event::assertNotDispatched(ReminderUpdatedEvent::class);
    }

    private function assertPushedGone(int $id): void
    {
        Event::assertDispatched(ReminderUpdatedEvent::class, function (ReminderUpdatedEvent $event) use ($id) {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'reminder.updated'
                && $this->channelOf($event) === "private-App.Models.User.{$this->user->id}"
                && $payload['id'] === $id
                && $payload['active'] === false
                && $payload['reminder'] === null;
        });
    }

    #[Test]
    public function dismissing_a_reminder_pushes_that_it_is_gone(): void
    {
        $reminder = $this->makeReminder();
        Event::fake([ReminderUpdatedEvent::class]);

        $this->postJson("/api/v1/common/reminders/{$reminder->id}/dismiss")->assertOk();

        $this->assertPushedGone($reminder->id);
    }

    #[Test]
    public function snoozing_a_reminder_pushes_that_it_is_gone_for_now(): void
    {
        $reminder = $this->makeReminder();
        Event::fake([ReminderUpdatedEvent::class]);

        $this->postJson("/api/v1/common/reminders/{$reminder->id}/snooze", ['minutes' => 15])->assertOk();

        $this->assertPushedGone($reminder->id);
    }

    #[Test]
    public function bulk_dismiss_and_dismiss_all_push_each_reminder_as_gone(): void
    {
        $a = $this->makeReminder();
        $b = $this->makeReminder();
        $c = $this->makeReminder();
        Event::fake([ReminderUpdatedEvent::class]);

        $this->postJson('/api/v1/common/reminders/bulk-dismiss', ['ids' => [$a->id, $b->id]])->assertOk();
        $this->postJson('/api/v1/common/reminders/dismiss-all')->assertOk();

        $this->assertPushedGone($a->id);
        $this->assertPushedGone($b->id);
        $this->assertPushedGone($c->id);
    }

    #[Test]
    public function editing_a_shown_reminder_pushes_its_new_details(): void
    {
        $reminder = $this->makeReminder();
        Event::fake([ReminderUpdatedEvent::class]);

        $reminder->update(['summary' => 'Call about the renewal']);

        Event::assertDispatched(ReminderUpdatedEvent::class, function (ReminderUpdatedEvent $event) use ($reminder) {
            $payload = $event->broadcastWith();

            return $payload['id'] === $reminder->id
                && $payload['active'] === true
                && $payload['reminder']['summary'] === 'Call about the renewal'
                && $payload['reminder']['display']['reminder_time'] === '2:00 PM';
        });
    }

    #[Test]
    public function deleting_a_shown_reminder_pushes_that_it_is_gone(): void
    {
        $reminder = $this->makeReminder();
        Event::fake([ReminderUpdatedEvent::class]);

        $reminder->delete();

        $this->assertPushedGone($reminder->id);
    }

    #[Test]
    public function changes_to_a_reminder_that_is_not_due_yet_push_nothing(): void
    {
        $reminder = $this->makeReminder([
            'status' => Reminder::STATUS_PENDING,
            'reminder_date_time' => '2026-10-06 08:00:00',
            'send_at' => '2026-10-06 08:00:00',
        ]);
        Event::fake([ReminderUpdatedEvent::class]);

        $reminder->update(['summary' => 'Still tomorrow']);

        Event::assertNotDispatched(ReminderUpdatedEvent::class);
    }

    #[Test]
    public function the_api_remembers_the_timezone_the_app_sends(): void
    {
        $this->withHeader('X-Timezone', 'America/Chicago')->getJson('/api/v1/common/reminders/due')->assertOk();
        $this->assertSame('America/Chicago', $this->user->fresh()->timezone);

        $this->withHeader('X-Timezone', 'Mars/Olympus_Mons')->getJson('/api/v1/common/reminders/due')->assertOk();
        $this->assertSame('America/Chicago', $this->user->fresh()->timezone);
    }
}
