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
        $this->info('Starting subscription processing...');
        Log::info('Artisan: subscriptions:process started');

        try {
            $this->info('Generating installment invoices...');
            $subscriptionService->generateInstallmentInvoices();

            $this->info('Generating platform fee invoices...');
            $subscriptionService->generatePlatformFeeInvoices();

            $this->info('Subscription processing completed successfully.');
            Log::info('Artisan: subscriptions:process completed');
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            Log::error('Artisan: subscriptions:process failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
