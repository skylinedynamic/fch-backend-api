<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Spatie Health Check Schedule
    |--------------------------------------------------------------------------
    |
    | Cron expression controlling how often `php artisan health:check` runs.
    | Use a less frequent expression on small instances to reduce load.
    |
    */
    'health_check_cron' => env('HEALTH_CHECK_CRON', '*/5 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Health Check History Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep historical health check results in the database.
    |
    */
    'health_keep_history_for_days' => env('HEALTH_KEEP_HISTORY_FOR_DAYS', 14),
];
