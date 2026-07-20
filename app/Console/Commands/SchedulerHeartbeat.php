<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        try {
            $now = now()->toDateTimeString();

            Cache::forever('scheduler.last_run_at', $now);

            $this->info("Scheduler heartbeat recorded at {$now}.");
            Log::info('Scheduler heartbeat recorded.', ['at' => $now]);

            $this->pingExternalHeartbeatUrl();

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Scheduler heartbeat failed: ' . $e->getMessage());
            Log::error('Scheduler heartbeat failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Ping an external heartbeat URL if one is configured.
     */
    private function pingExternalHeartbeatUrl(): void
    {
        $url = config('app.schedule_heartbeat_url');

        if (empty($url)) {
            return;
        }

        try {
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                Log::info('External scheduler heartbeat ping succeeded.', ['url' => $url]);
            } else {
                Log::warning('External scheduler heartbeat ping returned non-2xx.', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('External scheduler heartbeat ping failed: ' . $e->getMessage(), ['url' => $url]);
        }
    }
}
