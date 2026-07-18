<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler Health Heartbeat
|--------------------------------------------------------------------------
|
| This runs every minute and writes a cache timestamp. The custom
| SchedulerRanCheck health check uses this to verify the scheduler cron is
| actually running. If the timestamp goes stale, the health dashboard will
| report a failure.
|
*/
Schedule::command('scheduler:heartbeat')->everyMinute();

/*
|--------------------------------------------------------------------------
| Business Commands
|--------------------------------------------------------------------------
*/
Schedule::command('subscriptions:process')
    ->twiceDaily()
    ->withoutOverlapping()
    ->onFailure(fn() => Log::error('Scheduled command failed: subscriptions:process'));

Schedule::command('users:check-expired')
    ->twiceDaily()
    ->withoutOverlapping()
    ->onFailure(fn() => Log::error('Scheduled command failed: users:check-expired'));

/*
|--------------------------------------------------------------------------
| Monitoring / Maintenance Commands
|--------------------------------------------------------------------------
*/
Schedule::command('logs:prune-months')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(fn() => Log::error('Scheduled command failed: logs:prune-months'));

// Snap system resource metrics (CPU/RAM/Disk) for the charts dashboard.
Schedule::command('app:monitor-health')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onFailure(fn() => Log::error('Scheduled command failed: app:monitor-health'));

// Run Spatie health checks. Frequencies are env-driven so you can lower them
// on small instances to reduce load. Default every 5 minutes.
Schedule::command('health:check')
    ->cron(config('monitoring.health_check_cron', '*/5 * * * *'))
    ->withoutOverlapping()
    ->onFailure(fn() => Log::error('Scheduled command failed: health:check'));

// Purge monitoring history older than 1 month
Schedule::command('monitoring:prune')
    ->daily()
    ->withoutOverlapping()
    ->onFailure(fn() => Log::error('Scheduled command failed: monitoring:prune'));

/*
|--------------------------------------------------------------------------
| Backup Commands
|--------------------------------------------------------------------------
*/
Schedule::command('backup:run --only-db')
    ->dailyAt('01:00')
    ->withoutOverlapping(180)
    ->onFailure(fn() => Log::error('Scheduled command failed: backup:run --only-db'));

Schedule::command('backup:clean')
    ->dailyAt('02:00')
    ->withoutOverlapping(60)
    ->onFailure(fn() => Log::error('Scheduled command failed: backup:clean'));
