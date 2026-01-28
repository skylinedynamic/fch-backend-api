<?php

namespace App\Services\FCH\Common\PartnerPlan;

use App\Models\Partner\PartnerPlan;
use App\Traits\PaginatesApi;

class PartnerPlanService
{
    use PaginatesApi;

    /**
     * List all partner plans.
     */
    public function list()
    {
        return PartnerPlan::where('status', true)->get();
    }

    /**
     * Get a specific plan by slug or ID.
     */
    public function find($idOrSlug)
    {
        return PartnerPlan::where('id', $idOrSlug)
            ->orWhere('slug', $idOrSlug)
            ->firstOrFail();
    }
}
