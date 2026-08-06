<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User\User;
use App\Models\Employee\Employee;

class UserSeatsToSubscriptionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Fetch all users who have is_paid = 1 and end_date is set
        $paidUsers = DB::table('users')
            ->where('is_paid', 1)
            ->whereNotNull('end_date')
            ->get();

        foreach ($paidUsers as $user) {
            $entityType = $user->entity_type ?? User::class;
            $entityId = $user->entity_id ?? $user->id;

            // Check if subscription already exists for this entity to prevent duplicate entry
            $exists = DB::table('subscriptions')
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->exists();

            if (!$exists) {
                // Determine monthly_platform_fee
                // If there's an invoice for this user, get its amount. Otherwise fall back to config
                $lastInvoice = DB::table('invoices')
                    ->where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->where('status', 'paid')
                    ->orderBy('id', 'desc')
                    ->first();

                $amount = $lastInvoice ? $lastInvoice->payment_amount : 49.00;

                DB::table('subscriptions')->insert([
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'subscription_plan_id' => null,
                    'partner_type' => null,
                    'enrollment_type' => 'Monthly',
                    'monthly_platform_fee' => $amount,
                    'total_subscription_amount' => 0.00,
                    'payable_amount' => $amount,
                    'down_payment' => 0.00,
                    'first_payment_date' => $user->start_date,
                    'platform_fee_start_date' => $user->start_date,
                    'installment_type' => 0,
                    'installment_count' => 0,
                    'subscription_start_date' => $user->start_date,
                    'subscription_end_date' => $user->end_date,
                    'status' => $user->status === 'active' ? 'Active' : 'Pending',
                    'is_custom' => false,
                    'current_phase' => 'platform_fee',
                    'installments_paid' => 0,
                    'next_installment_date' => null,
                    'next_platform_fee_date' => $user->end_date,
                    'enrollment_completed_at' => $user->start_date,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
