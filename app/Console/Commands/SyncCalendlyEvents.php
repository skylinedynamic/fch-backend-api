<?php

namespace App\Console\Commands;

use App\Models\Calendly\CalendlyConnection;
use App\Services\Global\Calendly\CalendlySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The catch-up behind Calendly's webhooks: brings in every connected
 * Calendly's meetings (host, partners, borrowers - each with its own keys)
 * from a while back to a couple of months ahead, so one a webhook missed
 * still shows. One connection failing does not stop the others; the run then
 * fails so the schedule reports it.
 */
class SyncCalendlyEvents extends Command
{
    protected $signature = 'calendly:sync
        {--back=7 : days back to look}
        {--ahead=60 : days ahead to look}';

    protected $description = 'Bring every connected Calendly account\'s meetings into appointments';

    public function handle(CalendlySyncService $sync): int
    {
        $connections = CalendlyConnection::where('status', CalendlyConnection::STATUS_ACTIVE)->get()
            ->filter(fn (CalendlyConnection $connection) => $connection->isActive());

        if ($connections->isEmpty()) {
            $this->info('No Calendly is connected - nothing to sync.');

            return self::SUCCESS;
        }

        $failed = 0;
        $count = 0;

        foreach ($connections as $connection) {
            try {
                $count += $sync->syncRange(
                    $connection,
                    now()->subDays(max(0, (int) $this->option('back'))),
                    now()->addDays(max(1, (int) $this->option('ahead')))
                );
            } catch (Throwable $e) {
                $failed++;
                Log::warning('[CALENDLY] Scheduled sync failed', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
                $this->error("Calendly sync failed for connection {$connection->id}: ".$e->getMessage());
            }
        }

        $this->info("Synced {$count} Calendly meeting(s) from {$connections->count()} connection(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
