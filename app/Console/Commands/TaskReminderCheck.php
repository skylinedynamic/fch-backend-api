<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TaskReminderCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task:reminder-check';

    protected $description = 'Fallback safety-net sweep for due reminders whose exact-time '
        . 'DispatchReminderDueJob (queued at creation/snooze time) did not fire — e.g. the '
        . 'queue worker was down. Not the primary delivery path, so this can run coarsely.';

    public function handle()
    {
        \App\Models\Common\Reminder::with(['entity', 'creator'])
            ->due()
            ->chunkById(100, function ($reminders) {
                foreach ($reminders as $reminder) {
                    if ($reminder->creator) {
                        // Claimed atomically, as DispatchReminderDueJob does: a
                        // reminder the job (or a dismiss) changed since it was
                        // read here affects no row, and is not pushed again. A
                        // query-builder update, so no "updated" event is pushed
                        // on top of the due one either.
                        $claimed = \App\Models\Common\Reminder::where('id', $reminder->id)
                            ->where('status', \App\Models\Common\Reminder::STATUS_PENDING)
                            ->update(['status' => \App\Models\Common\Reminder::STATUS_SENT]);

                        if ($claimed === 0) {
                            continue;
                        }

                        $reminder->status = \App\Models\Common\Reminder::STATUS_SENT;
                        app(\App\Services\FCH\Common\Reminder\ReminderBroadcaster::class)->due($reminder);
                    }
                }
            });

        $this->info('Task reminder check completed successfully.');
    }
}
