<?php

namespace App\Services\FCH\Admin\Commission;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Http\Resources\FCH\Common\Commission\FundingApplicationCommissionResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\FCH\Common\Commission\CommissionPayoutService;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Support\Facades\Auth;

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
            
            $baseAmount = $fundingApplication->total_funded_amount > 0 
                ? $fundingApplication->total_funded_amount 
                : ($fundingApplication->requested_amount ?? 0);

            $totalCommission = $data['total_commission'];
            $adminCommission = $data['admin_commission'];
            $partnerCommission = $data['partner_commission'] ?? 0;

            $totalCommissionAmount = ($baseAmount * $totalCommission) / 100;
            
            if ($partnerCommission > 0) {
                $partnerCommissionAmount = ($totalCommissionAmount * $partnerCommission) / 100;
                $adminCommissionAmount = $totalCommissionAmount - $partnerCommissionAmount;
            } else {
                $adminCommission = 100;
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
                'commission_status' => Commission::STATUS_DRAFT,
                'payment_status' => Commission::PAYMENT_STATUS_PENDING,
            ]);
            
            DB::commit();
            
            Log::info('✅ ' . __('application.commission_saved_successfully'), [
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
            
            Log::error('❌ ' . __('application.error_saving_commission'), [
                'error' => $e->getMessage(),
                'funding_application_id' => $data['funding_application_id'] ?? null,
            ]);
            
            throw $e;
        }
    }

    /**
     * Process commission payout — to the partner, or for a client of the host
     * partner to the user assigned to that client. Records the payout, an
     * invoice and a payment (see CommissionPayoutService).
     *
     * @throws \Illuminate\Validation\ValidationException when there's nothing that can be paid yet
     */
    public function processPayout($commissionId, array $data)
    {
        // Host staff may see their own deals, but never pay themselves out.
        if (!CommissionViewer::for(Auth::user())->canPay()) {
            return ApiResponse::forbidden(__('commission.payout_not_allowed'));
        }

        $commission = app(CommissionPayoutService::class)->pay(
            Commission::findOrFail($commissionId),
            $data['partner_payment_method'],
            $data['note'] ?? null,
        );

        return ApiResponse::success(
            new CommissionResource($commission->fresh()),
            __('application.commission_payout_processed_successfully')
        );
    }
}
 