<?php

namespace App\Console\Commands;

use App\Models\Calendly\CalendlyConnection;
use App\Services\Global\Calendly\CalendlySyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The catch-up behind Calendly's webhooks: brings in the company account's
 * meetings from a while back to a couple of months ahead, so one a webhook
 * missed still shows. Nothing to do when Calendly is not connected.
 */
class SyncCalendlyEvents extends Command
{
    protected $signature = 'calendly:sync
        {--back=7 : days back to look}
        {--ahead=60 : days ahead to look}';

    protected $description = 'Bring the company Calendly account\'s meetings into appointments';

    public function handle(CalendlySyncService $sync): int
    {
        if (! CalendlyConnection::current()?->isActive()) {
            $this->info('Calendly is not connected - nothing to sync.');

            return self::SUCCESS;
        }

        try {
            $count = $sync->syncRange(
                now()->subDays(max(0, (int) $this->option('back'))),
                now()->addDays(max(1, (int) $this->option('ahead')))
            );
        } catch (Throwable $e) {
            $this->error('Calendly sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Synced {$count} Calendly meeting(s).");

        return self::SUCCESS;
    }
}
