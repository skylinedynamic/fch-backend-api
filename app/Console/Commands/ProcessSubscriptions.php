<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Support\Facades\Log;

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
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
