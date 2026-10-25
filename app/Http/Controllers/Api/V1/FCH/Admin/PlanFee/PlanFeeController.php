<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\PlanFee;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\FCH\Admin\PlanFee\PlanFeeService;
use App\Http\Requests\Api\V1\FCH\Admin\PlanFee\UpdateSubscriptionPlanRequest;
use App\Models\Partner\Partner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlanFeeController extends Controller
{
    public function __construct(protected PlanFeeService $planFeeService) {}

    /**
     * Retrieve all subscription plans for Plan & Fee view.
     * Accessible only by host partner admin (associated with is_host = 1 partner).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Access denied. Authentication required.', 403);
        }

        // Get the associated partner
        $partner = $user->partner;
        
        // Fallback to morph relation if partner_id is not directly set
        if (!$partner && ($user->entity_type === Partner::class || $user->entity_type === 'Partner')) {
            $partner = $user->entity; 
        }

        if (!$partner || $partner->is_host != 1) {
            return ApiResponse::error('Access denied. Host admin permissions required.', 403);
        }

        try {
            $plans = $this->planFeeService->getSubscriptionPlans();
            $groupedPlans = $plans->groupBy(function ($plan) {
                return $plan->group ?? 'unassigned';
            });
            return ApiResponse::success($groupedPlans, 'Subscription plans retrieved successfully.');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to retrieve subscription plans: ' . $e->getMessage());
        }
    }

    /**
     * Update a subscription plan's amounts.
     * Accessible only by host partner admin.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateSubscriptionPlanRequest $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Access denied. Authentication required.', 403);
        }

        $partner = $user->partner;
        if (!$partner && ($user->entity_type === Partner::class || $user->entity_type === 'Partner')) {
            $partner = $user->entity; 
        }

        if (!$partner || $partner->is_host != 1) {
            return ApiResponse::error('Access denied. Host admin permissions required.', 403);
        }

        // Each value is checked by UpdateSubscriptionPlanRequest (BUG-096).
        $validated = $request->validated();

        try {
            $plan = $this->planFeeService->updateSubscriptionPlan((int) $id, $validated);
            return ApiResponse::success($plan, 'Subscription plan updated successfully.');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to update subscription plan: ' . $e->getMessage());
        }
    }
}
