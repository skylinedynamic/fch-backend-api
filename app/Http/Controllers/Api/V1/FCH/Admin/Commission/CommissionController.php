<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Commission;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Commission\SaveCommissionRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Commission\ProcessPayoutRequest;
use App\Http\Resources\FCH\Admin\Commission\CommissionResource;
use App\Services\FCH\Admin\Commission\CommissionService;

use Throwable;

class CommissionController extends Controller
{
    protected CommissionService $commissionService;

    public function __construct(CommissionService $commissionService)
    {
        $this->commissionService = $commissionService;
    }



    /**
     * Save commission for a funding application.
     */
    public function saveCommission(SaveCommissionRequest $request)
    {
        try {
            $data = $request->validated();
            
            return $this->commissionService->saveCommission($data);
            
        } catch (Throwable $e) {
            return ApiResponse::serverError(__('application.error_saving_commission'), 500);
        }
    }

    /**
     * Process commission payout.
     */
    public function processPayout($id, ProcessPayoutRequest $request)
    {
        try {
            $data = $request->validated();
            return $this->commissionService->processPayout($id, $data);
        } catch (Throwable $e) {
            return ApiResponse::serverError(__('application.error_processing_payout'), 500);
        }
    }
}
