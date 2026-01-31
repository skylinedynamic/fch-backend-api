<?php

namespace App\Services\FCH\Common\SubscriptionPlan;

use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Partner\SubscriptionPlan;

class SubscriptionPlanService
{
    use PaginatesApi;

    /**
     * List all partner plans.
     */
    public function list()
    {
        return SubscriptionPlan::where('entity_type', Partner::class)->where('status', true)->get();
    }

    /**
     * Get a specific plan by slug or ID.
     */
    public function find($idOrSlug)
    {
        return SubscriptionPlan::where('id', $idOrSlug)
            ->firstOrFail();
    }

    /**
     * List all partner plans.
     */
    public function borrowerPlans()
    {
        $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_non_host_payment', false)
            ->where('status', true)
            ->get();

        return $plans;
    }

    /**
     * List all partner plans.
     */
    public function nonHostBorrowerPlans()
    {
        $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_non_host_payment', true)
            ->where('status', true)
            ->get();

        return $plans;
    }
}
