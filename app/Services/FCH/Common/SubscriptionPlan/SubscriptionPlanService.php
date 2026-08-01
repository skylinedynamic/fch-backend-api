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
        $user = Auth::user();
        if ($user && $user->partner && in_array($user->partner->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED])) {
            return SubscriptionPlan::where('entity_type', Borrower::class)
                ->where('is_partner_paying', false)
                ->where('status', true)
                ->get();
        }

        if (Auth::user()->user_type == User::PARTNER) {
            $plans = SubscriptionPlan::where('entity_type', Borrower::class)
                ->where('is_partner_paying', true)
                ->where('status', true)
                ->get();

        } else {
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
    public function nonHostBorrowerPlans($request = [])
    {
        $user = Auth::user();

        if ($user && $user->partner && in_array($user->partner->partner_type, [Partner::PARTNER_TYPE_WHITE_LABEL, Partner::PARTNER_TYPE_CERTIFIED])) {
            return SubscriptionPlan::where('entity_type', Borrower::class)
                ->where('is_partner_paying', false)
                ->active()
                ->get();
        }

        $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_partner_paying', true)
            ->where('status', true)
            ->get();

        return $plans;
    }
}
