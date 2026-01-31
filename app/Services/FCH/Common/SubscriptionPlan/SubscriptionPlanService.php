<?php

namespace App\Services\FCH\Common\SubscriptionPlan;

use App\Models\Partner\SubscriptionPlan;
use App\Traits\PaginatesApi;

class SubscriptionPlanService
{
    use PaginatesApi;

    /**
     * List all partner plans.
     */
    public function list()
    {
        return SubscriptionPlan::where('status', true)->get();
    }

    /**
     * Get a specific plan by slug or ID.
     */
    public function find($idOrSlug)
    {
        return SubscriptionPlan::where('id', $idOrSlug)
            ->firstOrFail();
    }
}
