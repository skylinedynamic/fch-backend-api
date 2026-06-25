<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\FundingRequest;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\FundingRequest\FetchFundingRequestRequest;
use App\Http\Requests\Api\V1\FCH\Common\FundingRequest\GetFundingRequestByIdRequest;
use App\Http\Requests\Api\V1\FCH\Common\FundingRequest\UpdateFundingRequestStatusRequest;
use App\Services\FCH\Common\FundingRequest\FundingRequestService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;

class FundingRequestController extends Controller
{
    protected $fundingRequestService;

    public function __construct(FundingRequestService $fundingRequestService)
    {
        $this->fundingRequestService = $fundingRequestService;
    }

    /**
     * Get Funding Request List
     */
    public function getFundingRequest(FetchFundingRequestRequest $request): JsonResponse
    {
        try {
            return $this->fundingRequestService->getFundingRequestList($request->validated());
        } catch (\Exception $e) {
            \Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
            return ApiResponse::serverError(__('funding_request.error_retrieving_funding_requests'));
        }
    }

    /**
     * Get Funding Request By ID
     */
    public function getFundingRequestById(GetFundingRequestByIdRequest $request, $id): JsonResponse
    {
        try {
            return $this->fundingRequestService->getFundingRequestById($id);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('funding_request.error_retrieving_details'));
        }
    }

    /**
     * Update Funding Request Status
     */
    public function fundingRequestStatusUpdate(UpdateFundingRequestStatusRequest $request): JsonResponse
    {
        try {
            return $this->fundingRequestService->fundingRequestStatusUpdate($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('funding_request.error_updating_status'));
        }
    }
}
