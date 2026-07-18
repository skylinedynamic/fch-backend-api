<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeat extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scheduler:heartbeat';

    /**
     * The console command description.
     */
    protected $description = 'Write a timestamp so the health dashboard can verify the scheduler is running';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Cache::forever('scheduler.last_run_at', now()->toDateTimeString());

        return self::SUCCESS;
    }
}
