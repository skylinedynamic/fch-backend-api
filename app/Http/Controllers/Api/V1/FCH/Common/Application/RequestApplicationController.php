<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Application;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Common\Application\FetchRequestApplicationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Application\UpdatePreQualificationStatusRequest;
use App\Http\Requests\Api\V1\FCH\Common\Application\UpdateFundingApplicationStatusRequest;
use App\Services\FCH\Common\Application\RequestApplicationService;
use Illuminate\Http\JsonResponse;

class RequestApplicationController extends Controller
{
    protected RequestApplicationService $requestApplicationService;

    public function __construct(RequestApplicationService $requestApplicationService)
    {
        $this->requestApplicationService = $requestApplicationService;
    }

    /**
     * Get Pre-Qualification Applications List
     */
    public function getPreQualification(FetchRequestApplicationRequest $request): JsonResponse
    {
        try {
            $input = $request->validated();
            
            // Standardize generic list filters
            $filters = [
                'per_page'      => $input['per_page'] ?? null,
                'page'          => $input['page'] ?? 1,
                'search'        => $input['search'] ?? null,
                'filters'       => $input['filters'] ?? [],
                'sort_field'    => $input['sort_field'] ?? null,
                'sort_order'    => $input['sort_order'] ?? null,
                'status'        => $input['status'] ?? null,
                'start_date'    => $input['start_date'] ?? null,
                'end_date'      => $input['end_date'] ?? null,
                'funding_type'  => $input['funding_type'] ?? null,
            ];

            return $this->requestApplicationService->getPreQualificationList($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_pre_qualification'));
        }
    }

    /**
     * Get Funding Applications List
     */
    public function getFundingApplication(FetchRequestApplicationRequest $request): JsonResponse
    {
        try {
            $input = $request->validated();

            $filters = [
                'per_page'      => $input['per_page'] ?? null,
                'page'          => $input['page'] ?? 1,
                'search'        => $input['search'] ?? null,
                'filters'       => $input['filters'] ?? [],
                'sort_field'    => $input['sort_field'] ?? null,
                'sort_order'    => $input['sort_order'] ?? null,
                'status'        => $input['status'] ?? null,
            ];

            return $this->requestApplicationService->getFundingApplicationList($filters);

        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_funding_application'));
        }
    }
    /**
     * Get Pre-Qualification Application By ID
     */
    public function getPreQualificationById($id): JsonResponse
    {
        try {
            return $this->requestApplicationService->getPreQualificationById($id);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_pre_qualification'));
        }
    }


    /**
     * Get Funding Application By ID
     */
    public function getFundingApplicationById($id): JsonResponse
    {
        try {
            return $this->requestApplicationService->getFundingApplicationById($id);
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_funding_application'));
        }
    }

    /**
     * Update Pre-Qualification Application Status
     */
    public function preQualificationStatusUpdate(UpdatePreQualificationStatusRequest $request): JsonResponse
    {
        try {
            return $this->requestApplicationService->preQualificationStatusUpdate($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_updating_status'));
        }
    }

    /**
     * Update Funding Application Status
     */
    public function fundingApplicationStatusUpdate(UpdateFundingApplicationStatusRequest $request): JsonResponse
    {
        try {
            return $this->requestApplicationService->fundingApplicationStatusUpdate($request->validated());
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_updating_status'));
        }
    }


    public function getPreQualificationCardSummary()
    {
        try {
            return $this->requestApplicationService->getPreQualificationCardSummary();
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_pre_qualification'));
        }
    }

    public function getFundingApplicationCardSummary()
    {
        try {
            return $this->requestApplicationService->getFundingApplicationCardSummary();
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('application.error_retrieving_funding_application'));
        }
    }
}
