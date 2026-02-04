<?php

namespace App\Services\FCH\Admin\Commission;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Http\Resources\FCH\Common\Commission\FundingApplicationCommissionResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Database\Eloquent\Builder;

class CommissionService
{
    use PaginatesApi;
    /**
     * Save commission data for a funding application.
     */
    public function saveCommission(array $data)
    {
        DB::beginTransaction();
        
        try {
            $fundingApplication = FundingApplication::with('borrower')->findOrFail($data['funding_application_id']);

            if(isset($data['requested_amount'])){
                $fundingApplication->update([
                    'requested_amount' => $data['requested_amount'],
                ]);
            }
            
            $requestedAmount = $fundingApplication->requested_amount ?? 0;
            $totalCommission = $data['total_commission'];
            $adminCommission = $data['admin_commission'];
            $partnerCommission = $data['partner_commission'] ?? 0;

            $totalCommissionAmount = ($requestedAmount * $totalCommission) / 100;
            
            if ($partnerCommission > 0) {
                $partnerCommissionAmount = ($totalCommissionAmount * $partnerCommission) / 100;
                $adminCommissionAmount = $totalCommissionAmount - $partnerCommissionAmount;
            } else {
                $adminCommission = $totalCommission;
                $adminCommissionAmount = $totalCommissionAmount;
                $partnerCommission = 0;
                $partnerCommissionAmount = 0;
            }

            Commission::updateOrCreate([
                'funding_application_id' => $fundingApplication->id,
            ], [
                'borrower_id' => $fundingApplication->borrower_id,
                'partner_id' => $fundingApplication->borrower->partner_id,
                'total_commission' => $totalCommission,
                'admin_commission' => $adminCommission,
                'partner_commission' => $partnerCommission,
                'total_commission_amount' => $totalCommissionAmount,
                'admin_commission_amount' => $adminCommissionAmount,
                'partner_commission_amount' => $partnerCommissionAmount,
                'commission_status' => Commission::STATUS_PENDING,
                'payment_status' => Commission::PAYMENT_STATUS_PENDING,
            ]);
            
            DB::commit();
            
            Log::info('✅ Commission saved successfully', [
                'funding_application_id' => $fundingApplication->id,
                'admin_commission' => $data['admin_commission'],
                'partner_commission' => $data['partner_commission'] ?? null,
            ]);
            
            return ApiResponse::success(
                new FundingApplicationCommissionResource($fundingApplication->fresh()),
                __('application.commission_saved_successfully')
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('❌ Failed to save commission', [
                'error' => $e->getMessage(),
                'funding_application_id' => $data['funding_application_id'] ?? null,
            ]);
            
            throw $e;
        }
    }
}
 