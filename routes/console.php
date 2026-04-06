<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscriptions:process')->twiceDaily();
Schedule::command('users:check-expired')->twiceDaily();
Schedule::command('logs:prune-months')->daily();

Schedule::command('app:monitor-health')->everyFiveMinutes();
