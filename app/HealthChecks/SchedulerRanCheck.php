<?php

namespace App\HealthChecks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class SchedulerRanCheck extends Check
{
    /**
     * Verify that the Laravel scheduler has run recently.
     *
     * A separate command (`scheduler:heartbeat`) writes a cache timestamp
     * every minute. If that timestamp is missing or stale, the scheduler
     * cron is probably not running.
     */
    public function run(): Result
    {
        $lastRun = Cache::get('scheduler.last_run_at');

        if (!$lastRun) {
            return Result::make()
                ->failed('Scheduler heartbeat not found. Is the `php artisan schedule:run` cron entry configured?');
        }

        $lastRunAt = Carbon::parse($lastRun);
        $thresholdMinutes = 2;

        if ($lastRunAt->lt(now()->subMinutes($thresholdMinutes))) {
            return Result::make()
                ->failed("Scheduler last ran {$lastRunAt->diffForHumans()}. The scheduler cron may be stuck.");
        }

        return Result::make()
            ->ok("Scheduler is running (last run {$lastRunAt->diffForHumans()}).");
    }
}
