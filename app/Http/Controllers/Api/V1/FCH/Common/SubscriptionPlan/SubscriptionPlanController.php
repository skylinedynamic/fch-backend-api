<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\SubscriptionPlan;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\SubscriptionPlan\SubscriptionPlanService;
use App\Http\Resources\FCH\Common\SubscriptionPlan\SubscriptionPlanResource;
use App\Http\Requests\Api\V1\FCH\Common\SubscriptionPlan\FetchSubscriptionPlanRequest;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        protected SubscriptionPlanService $subscriptionPlanService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(FetchSubscriptionPlanRequest $request)
    {
        $plans = $this->subscriptionPlanService->list($request->validated());

        return ApiResponse::success(
            SubscriptionPlanResource::collection($plans),
            __('partner_plan.success.listed')
        );
    }

    /**
     * Display the specified resource.
     */
    public function show($idOrSlug)
    {
        $plan = $this->subscriptionPlanService->find($idOrSlug);

        return ApiResponse::success(
            new SubscriptionPlanResource($plan),
            __('partner_plan.success.retrieved')
        );
    }

    /**
     * Display a listing of the resource.
     */
    public function borrowerPlans(FetchSubscriptionPlanRequest $request)
    {
        $plans = $this->subscriptionPlanService->borrowerPlans($request->validated());

        return ApiResponse::success(
            SubscriptionPlanResource::collection($plans),
            __('partner_plan.success.listed')
        );
    }

    /**
     * Display a listing of the resource.
     */
    public function nonHostBorrowerPlans(FetchSubscriptionPlanRequest $request)
    {
        $plans = $this->subscriptionPlanService->nonHostBorrowerPlans($request->validated());

        return ApiResponse::success(
            SubscriptionPlanResource::collection($plans),
            __('partner_plan.success.listed')
        );
    }
}
