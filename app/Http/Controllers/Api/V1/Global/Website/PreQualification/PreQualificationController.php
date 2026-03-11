<?php

namespace App\Http\Controllers\Api\V1\Global\Website\PreQualification;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\Global\Website\PreQualification\StorePreQualificationRequest;
use App\Services\Global\Website\PreQualification\PreQualificationService;
use Illuminate\Http\JsonResponse;

class PreQualificationController extends Controller
{
    public function __construct(
        protected PreQualificationService $preQualService
    ) {}

    /**
     * Store pre-qualification with all data (Personal + Business + Funding).
     */
    public function store(StorePreQualificationRequest $request): JsonResponse
    {
        $result = $this->preQualService->store($request->validated());

        return ApiResponse::success($result, __('lead.funding_requirements_submitted'));
    }
}
