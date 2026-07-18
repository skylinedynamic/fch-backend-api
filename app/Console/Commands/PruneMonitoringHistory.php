<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Monitoring\SystemMetric;
use Carbon\Carbon;

class PruneMonitoringHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitoring:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove system monitoring snapshots older than 1 month';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting monitoring data pruning...');

        $threshold = Carbon::now()->subMonth();

        // Chunk the deletion to avoid long table locks and memory spikes when the
        // monitoring history table grows large.
        $count = 0;
        do {
            $deleted = SystemMetric::where('created_at', '<', $threshold)->limit(1000)->delete();
            $count += $deleted;
        } while ($deleted > 0);

        $this->info("Pruning complete. Removed {$count} old snapshot records.");
    }
}
