<?php

namespace Tests\Integration\Console;

use App\Mail\CronJobFailureMail;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The schedule in routes/console.php as the scheduler sees it: what runs when,
 * that each entry names a command that exists, and that a failed run tells the
 * on-call list.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = [
        'subscriptions:process-all' => '0 1,13 * * *',
        // BUG-066: card payments left waiting on 3-D Secure.
        'payments:settle-card-authentications' => '*/5 * * * *',
        'funding:application-reminders' => '0 14 * * *',
        'task:reminder-check' => '*/5 * * * *',
        'scheduler:heartbeat' => '* * * * *',
        'horizon:snapshot' => '*/5 * * * *',
        'logs:prune-months' => '0 3 * * *',
        'app:monitor-health' => '*/5 * * * *',
        'health:check' => '*/5 * * * *',
        'monitoring:prune' => '30 3 * * *',
        'backup:run --only-db' => '0 4 * * *',
        'backup:clean' => '0 5 * * *',
        // The day's seal of the funding application audit trail.
        'audit:seal' => '55 23 * * *',
    ];

    /** @return array<string, Event> keyed by the command line after "artisan" */
    private function events(): array
    {
        Artisan::all();

        $events = [];
        foreach (app(Schedule::class)->events() as $event) {
            $events[trim(Str::after($event->command, 'artisan'), "'\" ")] = $event;
        }

        return $events;
    }

    #[Test]
    public function each_command_runs_at_its_time_in_the_scheduler_timezone(): void
    {
        $events = $this->events();

        $this->assertSame(self::EXPECTED, array_map(fn (Event $event) => $event->expression, $events));
        foreach ($events as $command => $event) {
            $this->assertSame(config('app.scheduler_timezone', config('app.timezone')), $event->timezone, $command);
        }
    }

    #[Test]
    public function every_scheduled_command_exists(): void
    {
        $registered = array_keys(Artisan::all());

        foreach (array_keys($this->events()) as $command) {
            $this->assertContains(Str::before($command, ' '), $registered, "{$command} is scheduled but not registered");
        }
    }

    #[Test]
    public function a_failed_run_mails_the_on_call_list(): void
    {
        Mail::fake();
        config(['app.cron_notify_emails' => 'ops@example.test, oncall@example.test']);

        foreach ($this->events() as $command => $event) {
            $event->finish(app(), 1);

            Mail::assertQueued(CronJobFailureMail::class, fn (CronJobFailureMail $mail) => $mail->hasTo('ops@example.test')
                && $mail->hasTo('oncall@example.test')
                && $mail->data['job_name'] === $command);
        }
        Mail::assertQueuedCount(count(self::EXPECTED));
    }

    #[Test]
    public function a_successful_run_sends_nothing(): void
    {
        Mail::fake();
        config(['app.cron_notify_emails' => 'ops@example.test']);

        foreach ($this->events() as $event) {
            $event->finish(app(), 0);
        }

        Mail::assertNothingOutgoing();
    }
}
