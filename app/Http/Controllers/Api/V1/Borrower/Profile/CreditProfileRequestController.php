<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\StoreCreditProfileRequestRequest;
use App\Http\Resources\Borrower\Profile\CreditProfileRequestResource;
use App\Services\Borrower\Profile\CreditProfileRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CreditProfileRequestController extends Controller
{
    protected CreditProfileRequestService $service;

    public function __construct(CreditProfileRequestService $service)
    {
        $this->service = $service;
    }

    /**
     * Store a new credit profile request and mark the borrower's credit profile as updated.
     */
    public function store(StoreCreditProfileRequestRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error('Unauthorized', 401);
            }

            $creditProfileRequest = $this->service->store($user, $request->validated());

            return ApiResponse::success(
                new CreditProfileRequestResource($creditProfileRequest),
                'Credit profile request submitted successfully.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }

    /**
     * Get the submitted credit profile request.
     */
    public function show(): JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->entity_id) {
                return ApiResponse::error('Unauthorized', 401);
            }

            $creditProfileRequest = $this->service->getLatestRequest($user->entity_id);

            return ApiResponse::success(
                new CreditProfileRequestResource($creditProfileRequest),
                'Credit profile request fetched successfully.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 500);
        }
    }
}

