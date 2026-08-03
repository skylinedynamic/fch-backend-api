<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User\User;
use App\Models\Employee\Employee;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
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

                $amount = $lastInvoice ? $lastInvoice->payment_amount : (double) config('app.per_user_price', 49.97);

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove only user/employee subscriptions that don't have standard plan IDs
        DB::table('subscriptions')
            ->whereIn('entity_type', [User::class, Employee::class])
            ->whereNull('subscription_plan_id')
            ->delete();
    }
};
