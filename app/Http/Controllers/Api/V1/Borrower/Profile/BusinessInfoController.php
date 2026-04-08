<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdateBusinessInfoRequest;
use App\Http\Resources\Borrower\Profile\BusinessInfoResource;
use App\Services\Borrower\Profile\BusinessInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BusinessInfoController extends Controller
{
    protected BusinessInfoService $businessInfoService;

    public function __construct(BusinessInfoService $businessInfoService)
    {
        $this->businessInfoService = $businessInfoService;
    }

    /**
     * Get Business Info.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $businessInfo = $this->businessInfoService->getBusinessInfo(Auth::id());

            if (!$businessInfo) {
                return ApiResponse::error(__('borrower.business_info_not_found'), 404);
            }

            return ApiResponse::success(
                new BusinessInfoResource($businessInfo),
                __('borrower.fetch_business_info')
            );

        }
        catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_business_info_failed'), 500);
        }
    }

    /**
     * Update Business Info.
     */
    public function update(UpdateBusinessInfoRequest $request)
    {
        try {
            $businessInfo = $this->businessInfoService->updateBusinessInfo(Auth::id(), $request->validated());

            return ApiResponse::success(
                new BusinessInfoResource($businessInfo),
                __('borrower.business_info_updated_successfully')
            );
        }
        catch (\Exception $e) {
            return ApiResponse::error(__('borrower.business_info_update_failed'), 500);
        }
    }
}
