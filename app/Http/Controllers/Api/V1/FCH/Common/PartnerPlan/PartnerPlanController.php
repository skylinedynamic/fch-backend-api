<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\PartnerPlan;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\PartnerPlan\PartnerPlanService;
use App\Http\Resources\FCH\Common\PartnerPlan\PartnerPlanResource;
use App\Http\Requests\Api\V1\FCH\Common\PartnerPlan\FetchPartnerPlanRequest;

class PartnerPlanController extends Controller
{
    public function __construct(
        protected PartnerPlanService $partnerPlanService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $plans = $this->partnerPlanService->list();
        
        return ApiResponse::success(
            PartnerPlanResource::collection($plans),
            __('partner_plan.success.listed')
        );
    }

    /**
     * Display the specified resource.
     */
    public function show($idOrSlug)
    {
        $plan = $this->partnerPlanService->find($idOrSlug);

        return ApiResponse::success(
            new PartnerPlanResource($plan),
            __('partner_plan.success.retrieved')
        );
    }
}
