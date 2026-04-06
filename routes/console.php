<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:process')->twiceDaily();
Schedule::command('users:check-expired')->twiceDaily();

// Run daily DB backup and clean up old backups over 7 days old
Schedule::command('backup:run --only-db')->dailyAt('01:00');
Schedule::command('backup:clean')->dailyAt('02:00');
