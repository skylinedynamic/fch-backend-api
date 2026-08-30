<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Mail\CronJobFailureMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SchedulerCheckController extends Controller
{
    private array $commands = ['subscriptions:process-all'];

    public function __invoke()
    {
        $today = now()->toDateString();

        $records = DB::table('scheduler_runs')
            ->where('date', $today)
            ->whereIn('command', $this->commands)
            ->get()
            ->keyBy('command');

        $hasIssue = false;
        $details = [];

        foreach ($this->commands as $command) {
            if (!isset($records[$command])) {
                $hasIssue = true;
                $details[] = [
                    'command' => $command,
                    'status' => 'no record',
                    'error_message' => 'No scheduler run record found for today.',
                ];
            } elseif ($records[$command]->status === 'failed') {
                $hasIssue = true;
                $details[] = [
                    'command' => $command,
                    'status' => 'failed',
                    'error_message' => $records[$command]->error_message ?? 'Unknown error',
                ];
            } else {
                $details[] = [
                    'command' => $command,
                    'status' => $records[$command]->status,
                    'error_message' => null,
                ];
            }
        }

        if ($hasIssue) {
            $this->sendAlert($details);
        }

        return response("OK");
    }

    private function sendAlert(array $details): void
    {
        $emails = array_filter(
            array_map('trim', explode(',', config('app.cron_notify_emails', '')))
        );

        if (empty($emails) || !config('mail.default')) {
            return;
        }

        $errorMessage = "";
        foreach ($details as $d) {
            $errorMessage .= "Command: {$d['command']}\n";
            $errorMessage .= "Status: {$d['status']}\n";
            if ($d['error_message']) {
                $errorMessage .= "Error: {$d['error_message']}\n";
            }
            $errorMessage .= "---\n";
        }

        try {
            Mail::to($emails)->send(new CronJobFailureMail([
                'job_name' => 'Scheduler Daily Check',
                'error_message' => trim($errorMessage),
                'timestamp' => now()->toDateTimeString(),
                'stack_trace' => json_encode($details, JSON_PRETTY_PRINT),
            ]));
            Log::warning('Scheduler check alert sent.', ['details' => $details]);
        } catch (\Throwable $e) {
            Log::error('Failed to send scheduler check alert: ' . $e->getMessage());
        }
    }
}
