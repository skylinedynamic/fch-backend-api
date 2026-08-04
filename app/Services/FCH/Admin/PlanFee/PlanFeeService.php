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

        $plan->update($updates);

        return $plan;
    }
}
