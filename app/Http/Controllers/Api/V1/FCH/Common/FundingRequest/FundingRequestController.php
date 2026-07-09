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

    /**
     * Assign Lender to Funding Request
     */
    public function assignLender(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|exists:funding_requests,id',
            'lender_ids' => 'nullable|array',
            'lender_ids.*' => 'integer|exists:lenders,id',
        ]);

        try {
            return $this->fundingRequestService->assignLender($request->all());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('funding_request.error_assigning_lender'));
        }
    }

    /**
     * Get Funding Sequences for Dropdowns (Common Access)
     */
    public function getFundingSequencing(\Illuminate\Http\Request $request, \App\Services\FCH\Admin\Funding\FundingSequencingService $sequencingService): JsonResponse
    {
        try {
            return $sequencingService->list($request->all());
        } catch (\Exception $e) {
            \Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
            return ApiResponse::serverError(__('funding_sequencing.list_failed'));
        }
    }

    /**
     * Get Grouped Funding Sequences (Common Access)
     */
    public function getGroupedFundingSequencing(\App\Services\FCH\Admin\Funding\FundingSequencingService $sequencingService): JsonResponse
    {
        try {
            $data = $sequencingService->getGroupedSequencings();
            return ApiResponse::success($data, __('funding_sequencing.grouped_list_fetched'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
            return ApiResponse::serverError(__('funding_sequencing.list_failed'));
        }
    }
}
