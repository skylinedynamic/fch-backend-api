<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FCH\Subscription\SubscriptionService;
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
        try {
            $subscriptionService->generateInstallmentInvoices();
            $subscriptionService->generatePlatformFeeInvoices();

            $this->info('Subscription processing completed.');
        } catch (\Exception $e) {
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
