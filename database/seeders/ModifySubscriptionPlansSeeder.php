<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModifySubscriptionPlansSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // 1. Update borrower_fee plans
        DB::table('subscription_plans')
            ->where('enrollment_type_slug', 'fixed-enrollment')
            ->update(['entity_type' => null, 'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_FEE]);

        // 2. Update Partner enrollment plans
        DB::table('subscription_plans')
            ->where('entity_type', Partner::class)
            ->orderBy('id')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    $updates = [
                        'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT,
                    ];

                    if ($plan->enrollment_type_slug === 'one-time-payment') {
                        $updates['total_installment_count_upto'] = 1;
                    } elseif ($plan->enrollment_type_slug === 'installment') {
                        $updates['total_installment_count_upto'] = 24;
                    }

                    DB::table('subscription_plans')
                        ->where('id', $plan->id)
                        ->update($updates);
                }
            });

        // 3. Update Borrower enrollment plans
        DB::table('subscription_plans')
            ->where('entity_type', Borrower::class)
            ->orderBy('id')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    $updates = [];

                    $updates['group'] = SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT;
                    if ($plan->enrollment_type_slug === 'one-time-payment') {
                        $updates['total_installment_count_upto'] = 1;
                    } elseif ($plan->enrollment_type_slug === 'installment') {
                        $updates['total_installment_count_upto'] = 24;
                    }

                    if (!empty($updates)) {
                        DB::table('subscription_plans')
                            ->where('id', $plan->id)
                            ->update($updates);
                    }
                }
            });
    }
}
