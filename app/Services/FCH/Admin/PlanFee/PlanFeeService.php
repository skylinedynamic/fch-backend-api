<?php

namespace App\Services\FCH\Admin\PlanFee;

use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Helpers\ApiResponse;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;

class PlanFeeService
{
    /**
     * Retrieve all active subscription plans.
     *
     * @return array
     */
    public function getSubscriptionPlans()
    {
        return SubscriptionPlan::active()->get();
    }

    /**
     * Update a subscription plan's financial amounts.
     *
     * @param int $id
     * @param array $data
     * @return SubscriptionPlan
     */
    public function updateSubscriptionPlan(int $id, array $data)
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $updates = [];
        if (array_key_exists('total_amount', $data)) {
            $updates['total_amount'] = $data['total_amount'];
        }
        if (array_key_exists('platform_fee', $data)) {
            $updates['platform_fee'] = $data['platform_fee'];
        }
        if (array_key_exists('total_installment_count_upto', $data)) {
            $updates['total_installment_count_upto'] = $data['total_installment_count_upto'];
        }
        if (array_key_exists('total_seat_count', $data)) {
            $updates['total_seat_count'] = $data['total_seat_count'];
        }

        $plan->update($updates);

        if (!empty($data['include_existing_subscription'])) {
            if ($plan->group === SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT && array_key_exists('total_amount', $data)) {
                // Only this plan's seats: seat plans are per partner type.
                Subscription::whereIn('entity_type', [User::class,Employee::class])
                    ->where('subscription_plan_id', $plan->id)
                    ->update([
                        'monthly_platform_fee' => $data['total_amount'],
                        'total_subscription_amount' => $data['total_amount'],
                        'payable_amount' => $data['total_amount'],
                        'per_seat_amount' => $data['total_amount']
                    ]);
            } elseif (array_key_exists('platform_fee', $data)) {
                Subscription::where('entity_type', Partner::class)
                    ->where('subscription_plan_id', $plan->id)
                    ->update(['monthly_platform_fee' => $data['platform_fee']]);
            }
        }

        return $plan;
    }
}
