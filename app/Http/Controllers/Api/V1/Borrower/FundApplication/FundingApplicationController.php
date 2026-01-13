<?php

namespace App\Http\Controllers\Api\V1\Borrower\FundApplication;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Borrower\FundApplication\FundingApplicationResource;
use App\Services\Borrower\FundApplication\FundingApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FundingApplicationController extends Controller
{
    protected FundingApplicationService $fundingApplicationService;

    public function __construct(FundingApplicationService $fundingApplicationService)
    {
        $this->fundingApplicationService = $fundingApplicationService;
    }

    /**
     * Get Business Info.
     *
     * @return JsonResponse
     */
    public function getBusinessInfo(): JsonResponse
    {
        try {
            $businessInfo = $this->fundingApplicationService->getBusinessInfo();
            
            if (!$businessInfo) {
                return ApiResponse::error(__('borrower.business_info_not_found'), 404);
            }

            return ApiResponse::success(
                new FundingApplicationResource($businessInfo),
                __('borrower.fetch_business_info')
            );

        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.fetch_business_info_failed'), 500);
        }
    }


    /**
     * Save Business Info.
     */
    public function saveBusinessInfo()
    {
      
    }
}
