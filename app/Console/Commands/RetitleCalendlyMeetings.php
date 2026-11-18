<?php

namespace App\Console\Commands;

use App\Models\Appointment\Appointment;
use App\Services\FCH\Common\Calendly\CalendlyService;
use App\Services\Global\Calendly\CalendlyException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upcoming Calendly meetings whose name in Calendly is not their FCH title -
 * those booked before meetings were named after the title ("This is test
 * meeting") - booked again under the title: same time and person, the old
 * one cancelled (Calendly cannot rename a booked meeting). Each one is a new
 * Calendly booking, so it counts toward Calendly's daily limit (Free plan: 5)
 * and the invitee gets Calendly's cancellation and new invitation.
 *
 * Run with --dry-run first: it only reads Calendly and lists what differs.
 */
class RetitleCalendlyMeetings extends Command
{
    protected $signature = 'calendly:retitle
        {--dry-run : List the meetings whose Calendly name is not their FCH title; change nothing}
        {--id=* : Only these appointment ids}
        {--force : Do not ask for confirmation}';

    protected $description = 'Give upcoming Calendly meetings their FCH appointment title in Calendly';

    public function handle(CalendlyService $calendly): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run: nothing will be changed.');
        }

        $appointments = Appointment::query()
            ->whereNotNull('calendly_event_uri')
            ->when($this->option('id'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('appointment_date')
            ->get()
            ->filter(fn (Appointment $appointment) => $appointment->isCancellable());

        $differ = [];
        $rows = [];
        foreach ($appointments as $appointment) {
            try {
                $name = $calendly->calendlyNameOf($appointment);
            } catch (Throwable $e) {
                $name = null;
                $this->error("#{$appointment->id}: Calendly could not be read - {$e->getMessage()}");
            }
            $same = $name !== null && $name === (string) $appointment->subject;
            $rows[] = [$appointment->id, $appointment->subject, $name ?? '(not readable)', $appointment->appointment_date.' UTC', $name === null ? 'n/a' : ($same ? 'YES' : 'NO')];
            if ($name !== null && ! $same) {
                $differ[] = $appointment;
            }
        }

        $this->table(['Appointment', 'FCH title', 'Calendly name', 'When', 'Same title'], $rows);
        $this->info('Upcoming Calendly meetings to give their FCH title: '.count($differ));

        if ($dryRun || $differ === []) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Book '.count($differ).' meeting(s) again in Calendly under their FCH title? (each uses one Calendly booking and emails the invitee)')) {
            $this->line('Nothing changed.');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($differ as $appointment) {
            try {
                $updated = $calendly->retitleInCalendly($appointment);
                $now = $calendly->calendlyNameOf($updated);
                $ok = $now === (string) $updated->subject;
                $this->line(sprintf('#%d: Calendly name now "%s" - %s', $updated->id, $now, $ok ? 'YES, same title' : 'NO'));
                $fixed += $ok ? 1 : 0;
            } catch (CalendlyException $e) {
                $this->error("#{$appointment->id}: {$e->getMessage()}");
                Log::warning('[CALENDLY] Retitle failed', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
                // Calendly's daily booking limit: the rest would be refused too.
                if ($e->calendlyStatus() === 429) {
                    $this->warn('Calendly\'s daily booking limit reached - run again tomorrow for the rest.');
                    break;
                }
            } catch (Throwable $e) {
                $this->error("#{$appointment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Now named after their FCH title: {$fixed} of ".count($differ));

        return $fixed === count($differ) ? self::SUCCESS : self::FAILURE;
    }
}
