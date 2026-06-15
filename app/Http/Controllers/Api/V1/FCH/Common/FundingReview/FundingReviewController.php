<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\FundingReview;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\FundingReview\FetchFundingReviewRequest;
use App\Http\Requests\Api\V1\FCH\Common\FundingReview\GetFundingReviewByIdRequest;
use App\Http\Requests\Api\V1\FCH\Common\FundingReview\UpdateFundingReviewStatusRequest;
use App\Services\FCH\Common\FundingReview\FundingReviewService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;

class FundingReviewController extends Controller
{
    protected $fundingReviewService;

    public function __construct(FundingReviewService $fundingReviewService)
    {
        $this->fundingReviewService = $fundingReviewService;
    }

    /**
     * Get Funding Review List
     */
    public function getFundingReview(FetchFundingReviewRequest $request): JsonResponse
    {
        try {
            return $this->fundingReviewService->getFundingReviewList($request->validated());
        } catch (\Exception $e) {
            \Log::error($e->getMessage() . ' - ' . $e->getTraceAsString());
            return ApiResponse::serverError(__('funding_review.error_retrieving_funding_reviews'));
        }
    }

    /**
     * Get Funding Review By ID
     */
    public function getFundingReviewById(GetFundingReviewByIdRequest $request, $id): JsonResponse
    {
        try {
            return $this->fundingReviewService->getFundingReviewById($id);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('funding_review.error_retrieving_details'));
        }
    }

    /**
     * Update Funding Review Status
     */
    public function fundingReviewStatusUpdate(UpdateFundingReviewStatusRequest $request): JsonResponse
    {
        try {
            return $this->fundingReviewService->fundingReviewStatusUpdate($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('funding_review.error_updating_status'));
        }
    }
}
