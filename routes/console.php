<?php

use App\Mail\CronJobFailureMail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Notes on this schedule
|--------------------------------------------------------------------------
|
| - All times are explicit (no bare ->daily()/->twiceDaily()) so the full
|   day's timeline is visible here in code, and nothing collides silently.
| - Timezone is pinned so "01:00" means the same thing regardless of the
|   host server's system tz.
| - onFailure() both logs AND notifies, so a failure is never silent.
| - withoutOverlapping() lock windows are set explicitly per job based on
|   realistic run time, rather than relying on the 24h default lock.
|
*/

$scheduleTimezone = config('app.scheduler_timezone', config('app.timezone', 'UTC'));
// In the month's folder, like the app log, so it is pruned with it - one file
// appended to for ever was never old enough for the loose-file rule to remove.
$schedulerLogPath = storage_path('logs/' . now()->format('Y/F') . '/scheduler.log');
\Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($schedulerLogPath));

/**
 * Shared failure handler: logs AND notifies the on-call email list using the host admin email configuration.
 */
$onFailure = function (string $command) {
    return function () use ($command) {
        $env = config('app.env', 'unknown');
        $appUrl = config('app.url', 'unknown');
        $hostname = gethostname() ?: 'unknown';

        Log::error("Scheduled command failed: {$command}", [
            'env' => $env,
            'app_url' => $appUrl,
            'hostname' => $hostname,
        ]);

        $emails = array_filter(
            array_map('trim', explode(',', config('app.cron_notify_emails', '')))
        );

        if (!empty($emails)) {
            try {
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name' => $command,
                    'error_message' => "Scheduled command failed on [{$env}] server ({$hostname})",
                    'timestamp' => now()->toDateTimeString(),
                    'stack_trace' => implode("\n", [
                        "Scheduled command failed: {$command}",
                        "",
                        "--- Origin ---",
                        "Environment : {$env}",
                        "App URL     : {$appUrl}",
                        "Server Host : {$hostname}",
                    ]),
                ]));
            } catch (\Throwable $e) {
                Log::error('Failed to send cron failure notification: ' . $e->getMessage());
            }
        }
    };
};

/**
 * Shared success handler: logs the success of scheduled commands.
 */
$onSuccess = function (string $command) {
    return function () use ($command) {
        Log::info("Scheduled command succeeded: {$command}");
    };
};



/*
|--------------------------------------------------------------------------
| Business Commands  —  01:00 / 13:00 window, staggered 15 min apart
|--------------------------------------------------------------------------
*/
// Unified subscription lifecycle: generate invoices for all types + auto-charge due invoices
Schedule::command('subscriptions:process-all')
    ->twiceDailyAt(1, 13, 0)
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(60)
    ->appendOutputTo($schedulerLogPath)
    ->onFailure($onFailure('subscriptions:process-all'))
    ->onSuccess($onSuccess('subscriptions:process-all'));

// Card payments waiting on 3-D Secure (BUG-066): settles the ones whose payer
// closed the tab mid-challenge, and cancels the ones left unfinished too long.
// Frequent, because an authenticated charge must be recorded before the
// auto-charge pass above could take the same invoice again.
Schedule::command('payments:settle-card-authentications')
    ->everyFiveMinutes()
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(10)
    ->onFailure($onFailure('payments:settle-card-authentications'));


Schedule::command('funding:application-reminders')
    ->dailyAt('14:00')
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(30)
    ->onFailure($onFailure('funding:application-reminders'));

// Fallback sweep only — real-time delivery now happens via DispatchReminderDueJob,
// a queued job scheduled for each reminder's exact due timestamp (see
// ReminderService::createFromActivity / snoozeReminder). This just catches
// reminders that job missed (e.g. a queue worker outage), so it doesn't need
// minute-level resolution.
Schedule::command('task:reminder-check')
    ->everyFiveMinutes()
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(5)
    ->onFailure($onFailure('task:reminder-check'));

/*
|--------------------------------------------------------------------------
| Scheduler Health Heartbeat
|--------------------------------------------------------------------------
|
| Runs every minute and writes a cache timestamp. The custom
| SchedulerRanCheck health check uses this to verify the scheduler cron is
| actually running. If the timestamp goes stale, the health dashboard will
| report a failure.
|
*/
Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->timezone($scheduleTimezone)
    ->onFailure($onFailure('scheduler:heartbeat'));

/*
|--------------------------------------------------------------------------
| Monitoring / Maintenance Commands
|--------------------------------------------------------------------------
*/
// Feeds the Metrics tab of the Horizon dashboard (Queue Jobs menu): without a
// snapshot every few minutes its throughput/runtime graphs stay empty.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->timezone($scheduleTimezone)
    ->onFailure($onFailure('horizon:snapshot'));

Schedule::command('logs:prune-months')
    ->dailyAt('03:00')
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(30)
    ->onFailure($onFailure('logs:prune-months'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('logs:prune-months'));

// Snap system resource metrics (CPU/RAM/Disk) for the charts dashboard.
Schedule::command('app:monitor-health')
    ->everyFiveMinutes()
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(4)
    ->onFailure($onFailure('app:monitor-health'));

// Run Spatie health checks. Frequency is env-driven so you can lower it
// on small instances to reduce load. Default every 5 minutes.
Schedule::command('health:check')
    ->cron(config('monitoring.health_check_cron', '*/5 * * * *'))
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(4)
    ->onFailure($onFailure('health:check'));

// Purge monitoring history older than 1 month
Schedule::command('monitoring:prune')
    ->dailyAt('03:30')
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(30)
    ->onFailure($onFailure('monitoring:prune'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('monitoring:prune'));

/*
|--------------------------------------------------------------------------
| Backup Commands  —  kept well clear of business jobs at 01:00/13:00
|--------------------------------------------------------------------------
*/
Schedule::command('backup:run --only-db')
    ->dailyAt('04:00')
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(180)
    ->onFailure($onFailure('backup:run --only-db'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('backup:run --only-db'));

Schedule::command('backup:clean')
    ->dailyAt('05:00')
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(60)
    ->onFailure($onFailure('backup:clean'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('backup:clean'));