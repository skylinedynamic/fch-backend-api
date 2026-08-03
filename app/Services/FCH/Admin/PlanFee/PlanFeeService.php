<?php

namespace App\Services\FCH\Admin\PlanFee;

use App\Models\Partner\SubscriptionPlan;
use App\Helpers\ApiResponse;

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

        $plan->update(array_filter([
            'total_amount' => $data['total_amount'] ?? null,
            'platform_fee' => $data['platform_fee'] ?? null,
        ], function ($value) {
            return $value !== null;
        }));

        return $plan;
    }
}
