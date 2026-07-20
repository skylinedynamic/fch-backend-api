<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\CronJobFailureMail;

class ProcessSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate invoices for installments and platform fees';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionService $subscriptionService)
    {
        //This is for checking if schedular is runing or not
        $runId = DB::table('scheduler_runs')->insertGetId([
            'command' => 'subscriptions:process',
            'date' => now()->toDateString(),
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $subscriptionService->generateInstallmentInvoices();
            $subscriptionService->generatePlatformFeeInvoices();

            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info('Subscription processing completed.');
        } catch (\Exception $e) {
            DB::table('scheduler_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
                'updated_at' => now(),
            ]);

            $this->error("ProcessSubscriptions Job Failed: " . $e->getMessage());

            $emails = explode(',', config('app.cron_notify_emails', ''));
            $emails = array_filter(array_map('trim', $emails));

            if (!empty($emails)) {
                Mail::to($emails)->send(new CronJobFailureMail([
                    'job_name' => 'ProcessSubscriptions (subscriptions:process)',
                    'error_message' => $e->getMessage(),
                    'timestamp' => now()->toDateTimeString(),
                    'stack_trace' => $e->getTraceAsString()
                ]));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
