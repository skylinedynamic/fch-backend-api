<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Partner\SubscriptionPlan;

class SubscriptionPlanUpdateNameUpdate extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Update One Time Payment plans
        DB::table('subscription_plans')
            ->where('enrollment_type', SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT)
            ->orWhere('enrollment_type_slug', SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG)
            ->update([
                'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
                'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            ]);

            DB::table('subscription_plans')
            ->where('enrollment_type', SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT)
            ->orWhere('enrollment_type_slug', SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG)
            ->update([
                'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
                'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG,
            ]);


        // 2. Update Fixed Enrollment (Borrower Fee) plans
        DB::table('subscription_plans')
            ->where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE)
            ->update([
                'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT,
                'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_BORROWER_FIXED_ENROLLMENT_SLUG,
            ]);

        // 3. Update User Seat / Monthly plans
        DB::table('subscription_plans')
            ->where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
            ->update([
                'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_MONTHLY_SEAT_FEE,
                'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_MONTHLY_SEAT_FEE_SLUG,
            ]);
    }
}
