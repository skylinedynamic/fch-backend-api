<?php

namespace App\Http\Controllers\Api\V1\Global\Website\Lead;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\Global\Website\Lead\StoreLeadRequest;
use App\Http\Resources\Global\Website\Lead\LeadResource;
use App\Services\Global\Website\Lead\LeadService;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function __construct(
        protected LeadService $leadService
    ) {}

    /**
     * Store a new lead from the website.
     */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = $this->leadService->store($request->validated());

        return ApiResponse::success(
            new LeadResource($lead),
            'Lead created successfully.'
        );
    }
}
