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

    protected $description = 'Check for due reminders and dispatch events';

    public function handle()
    {
        \App\Models\Common\Reminder::with(['entity', 'creator'])
            ->due()
            ->chunkById(100, function ($reminders) {
                foreach ($reminders as $reminder) {
                    if ($reminder->creator) {
                        event(new \App\Events\ReminderDueEvent($reminder));
                    }
                }
            });
            
        $this->info('Task reminder check completed successfully.');
    }
}
