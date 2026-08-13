<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestingEnrollmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!$this->command) {
            echo "This seeder must be run from the artisan command line.\n";
            return;
        }

        $subscriptionIdInput = $this->command->ask('Please enter Subscription ID');
        if (!$subscriptionIdInput || !is_numeric($subscriptionIdInput)) {
            $this->command->error('Invalid Subscription ID provided.');
            return;
        }

        $subscriptionId = (int) $subscriptionIdInput;
        $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->first();

        if (!$subscription) {
            $this->command->error("Subscription with ID {$subscriptionId} not found.");
            return;
        }

        $daysInput = $this->command->ask('How Many Days to subtract?');
        if (!is_numeric($daysInput)) {
            $this->command->error('Invalid number of days provided.');
            return;
        }

        $days = (int) $daysInput;

        $this->command->info("Subtracting {$days} days for Subscription ID: {$subscriptionId}...");

        // 1. Update Subscriptions Table (only if field is not null)
        $subUpdate = [];

        if (!is_null($subscription->first_payment_date)) {
            $subUpdate['first_payment_date'] = Carbon::parse($subscription->first_payment_date)->subDays($days);
        }

        if (!is_null($subscription->subscription_start_date)) {
            $subUpdate['subscription_start_date'] = Carbon::parse($subscription->subscription_start_date)->subDays($days);
        }

        if (!is_null($subscription->subscription_end_date)) {
            $subUpdate['subscription_end_date'] = Carbon::parse($subscription->subscription_end_date)->subDays($days);
        }

        if (!is_null($subscription->enrollment_completed_at)) {
            $subUpdate['enrollment_completed_at'] = Carbon::parse($subscription->enrollment_completed_at)->subDays($days);
        }

        if (!is_null($subscription->created_at)) {
            $subUpdate['created_at'] = Carbon::parse($subscription->created_at)->subDays($days);
        }

        if (!is_null($subscription->updated_at)) {
            $subUpdate['updated_at'] = Carbon::parse($subscription->updated_at)->subDays($days);
        }

        if (!empty($subUpdate)) {
            DB::table('subscriptions')->where('id', $subscriptionId)->update($subUpdate);
            $this->command->info("Updated subscriptions table for Subscription ID {$subscriptionId}.");
        } else {
            $this->command->info("No non-null date fields to update in subscriptions table.");
        }

        // 2. Update Invoices Table (only if field is not null)
        $invoices = DB::table('invoices')->where('subscription_id', $subscriptionId)->get();
        $invoiceIds = $invoices->pluck('id')->toArray();

        foreach ($invoices as $invoice) {
            $invUpdate = [];

            if (!is_null($invoice->payment_date)) {
                $invUpdate['payment_date'] = Carbon::parse($invoice->payment_date)->subDays($days);
            }

            if (!is_null($invoice->created_at)) {
                $invUpdate['created_at'] = Carbon::parse($invoice->created_at)->subDays($days);
            }

            if (!is_null($invoice->updated_at)) {
                $invUpdate['updated_at'] = Carbon::parse($invoice->updated_at)->subDays($days);
            }

            if (!empty($invUpdate)) {
                DB::table('invoices')->where('id', $invoice->id)->update($invUpdate);
            }
        }

        $this->command->info("Updated " . count($invoices) . " invoice(s) for Subscription ID {$subscriptionId}.");

        // 3. Update Payments Table (only if field is not null)
        $paymentsCount = 0;
        if (!empty($invoiceIds)) {
            $payments = DB::table('payments')->whereIn('invoice_id', $invoiceIds)->get();
            $paymentsCount = $payments->count();

            foreach ($payments as $payment) {
                $payUpdate = [];

                if (!is_null($payment->transaction_date)) {
                    $payUpdate['transaction_date'] = Carbon::parse($payment->transaction_date)->subDays($days);
                }

                if (!is_null($payment->created_at)) {
                    $payUpdate['created_at'] = Carbon::parse($payment->created_at)->subDays($days);
                }

                if (!is_null($payment->updated_at)) {
                    $payUpdate['updated_at'] = Carbon::parse($payment->updated_at)->subDays($days);
                }

                if (!empty($payUpdate)) {
                    DB::table('payments')->where('id', $payment->id)->update($payUpdate);
                }
            }
        }

        $this->command->info("Updated {$paymentsCount} payment(s) associated with invoices.");
        $this->command->info("Successfully subtracted {$days} days from Subscription #{$subscriptionId}, its invoices, and payments!");
    }
}
