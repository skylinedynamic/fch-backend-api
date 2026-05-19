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
         if ($user && $user->partner && $user->partner->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL) {
             return SubscriptionPlan::where('entity_type', Borrower::class)
                 ->whereIn('enrollment_type_slug', [
                     SubscriptionPlan::BORROWER_ENROLMENT_TYPE_ONE_TIME,
                     SubscriptionPlan::BORROWER_ENROLMENT_TYPE_INSTALLMENT
                 ])
                 ->where('status', true)
                 ->get();
         }

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
         $user = Auth::user();
         Log::info($user->partner->partner_type);
         Log::info('ARafat');
         
         if ($user && $user->partner && $user->partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
             return SubscriptionPlan::where('entity_type', Borrower::class)
                 ->whereIn('enrollment_type_slug', [
                     SubscriptionPlan::BORROWER_ENROLMENT_TYPE_ONE_TIME,
                     SubscriptionPlan::BORROWER_ENROLMENT_TYPE_INSTALLMENT
                 ])
                 ->get();
         }

        $plans = SubscriptionPlan::where('entity_type', Borrower::class)
            ->where('is_partner_paying', true)
            ->where('status', true)
            ->get();

        return $plans;
    }
}
