<?php

namespace App\Services\FCH\Common\SubscriptionPlan;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
            ->orWhere('enrollment_type_slug', $idOrSlug)
            ->firstOrFail();
    }

    /**
     * List all partner plans.
     */
    public function borrowerPlans()
    {
        return SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_partner_paying', false)
            ->where('status', true)
            ->get();
    }

}
