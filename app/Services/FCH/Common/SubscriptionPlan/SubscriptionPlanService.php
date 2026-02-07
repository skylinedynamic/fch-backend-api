<?php

namespace App\Services\FCH\Common\SubscriptionPlan;

use App\Traits\PaginatesApi;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use App\Models\Partner\SubscriptionPlan;
use App\Models\User\User;
use Illuminate\Support\Facades\Auth;

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
         if(Auth::user()->user_type == User::PARTNER) {
            $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_partner_paying', true)
            ->where('status', true)
            ->get();
            
         }else{
            $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_partner_paying', false)
            ->where('status', true)
            ->get();
         }

        return $plans;
    }

    /**
     * List all partner plans.
     */
    public function nonHostBorrowerPlans()
    {
        $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_partner_paying', true)
            ->where('status', true)
            ->get();

        return $plans;
    }
}
