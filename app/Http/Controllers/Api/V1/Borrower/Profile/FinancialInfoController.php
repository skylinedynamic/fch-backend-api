<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdateFinancialInfoRequest;
use App\Http\Resources\Borrower\Profile\FinancialInfoResource;
use App\Services\Borrower\Profile\FinancialInfoService;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;

class FinancialInfoController extends Controller
{
    protected $financialInfoService;

    public function __construct(FinancialInfoService $financialInfoService)
    {
        $this->financialInfoService = $financialInfoService;
    }

    /**
     * Get Financial Info
     */
    public function index()
    {
        try {   
            $financialInfo = $this->financialInfoService->getFinancialInfo();
            
            if (!$financialInfo) {
                return ApiResponse::error(__('borrower.financial_info_not_found'), 404);
            }
            return ApiResponse::success(new FinancialInfoResource($financialInfo),__('borrower.financial_info_fetched_successfully'));
        
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.financial_info_fetch_failed'), 500);
        }
    }

    /**
     * Update Financial Info
     */
    public function update(UpdateFinancialInfoRequest $request)
    {
        try {
            $updatedFinancialInfo = $this->financialInfoService->updateFinancialInfo($request->validated());
            
            if (!$updatedFinancialInfo) {
                return ApiResponse::error(__('borrower.financial_info_update_failed'), 400);
            }

            return ApiResponse::success(
                new FinancialInfoResource($updatedFinancialInfo),
                 __('borrower.financial_info_updated_successfully')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__('borrower.financial_info_update_failed'), 500);
        }
    }
}
