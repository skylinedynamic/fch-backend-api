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
                        // Quietly, so marking it sent doesn't also push an
                        // "updated" event on top of the due one.
                        $reminder->forceFill(['status' => \App\Models\Common\Reminder::STATUS_SENT])->saveQuietly();
                        app(\App\Services\FCH\Common\Reminder\ReminderBroadcaster::class)->due($reminder);
                    }
                }
            });

        $this->info('Task reminder check completed successfully.');
    }
}
