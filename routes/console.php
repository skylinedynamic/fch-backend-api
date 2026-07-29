<?php

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
$schedulerLogPath = storage_path('logs/scheduler.log');

/**
 * Shared failure handler: logs AND notifies the on-call email list.
 */
$onFailure = function (string $command) {
    return function () use ($command) {
        $message = "Scheduled command failed: {$command}";

        Log::error($message);

        $emails = array_filter(
            array_map('trim', explode(',', config('app.cron_notify_emails', '')))
        );

        if (!empty($emails) && config('mail.default')) {
            try {
                Mail::raw($message, function ($mail) use ($emails, $command) {
                    $mail->to($emails)->subject("Scheduler failure: {$command}");
                });
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
Schedule::command('subscriptions:process')
    ->twiceDailyAt(1, 13, 0)
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(30)
    ->onFailure($onFailure('subscriptions:process'));

// Auto-charge due subscription invoices via saved payment methods (runs after subscriptions:process)
Schedule::command('subscriptions:auto-charge')
    ->twiceDailyAt(1, 13, 15)
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(30)
    ->onFailure($onFailure('subscriptions:auto-charge'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('subscriptions:auto-charge'));

Schedule::command('users:check-expired')
    ->twiceDailyAt(1, 13, 15)
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(30)
    ->onFailure($onFailure('users:check-expired'));

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
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('scheduler:heartbeat'));

/*
|--------------------------------------------------------------------------
| Monitoring / Maintenance Commands
|--------------------------------------------------------------------------
*/
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
    ->onFailure($onFailure('app:monitor-health'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('app:monitor-health'));

// Run Spatie health checks. Frequency is env-driven so you can lower it
// on small instances to reduce load. Default every 5 minutes.
Schedule::command('health:check')
    ->cron(config('monitoring.health_check_cron', '*/5 * * * *'))
    ->timezone($scheduleTimezone)
    ->withoutOverlapping(4)
    ->onFailure($onFailure('health:check'))
    ->appendOutputTo($schedulerLogPath)
    ->onSuccess($onSuccess('health:check'));

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