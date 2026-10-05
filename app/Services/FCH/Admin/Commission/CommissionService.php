<?php

namespace App\Services\FCH\Admin\Commission;

use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Commission\CommissionResource;
use App\Http\Resources\FCH\Common\Commission\FundingApplicationCommissionResource;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Invoice\Invoice;
use App\Models\Payment\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\FCH\Common\Invoice\InvoiceService;

use App\Services\Global\Datatable\QueryFilter;
use App\Traits\PaginatesApi;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Notification\Notifier;
use App\Mail\Commission\CommissionPayoutMail;

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
     * Process commission payout.
     */
    public function processPayout($commissionId, array $data)
    {
        DB::beginTransaction();

        try {
            $commission = Commission::with(['partner', 'borrower'])->findOrFail($commissionId);

            if ($commission->payment_status === Commission::PAYMENT_STATUS_PAID) {
                return ApiResponse::error(__('application.commission_already_paid'), 400);
            }

            // Only create invoice/payment for partner if partner exists and has commission > 0
            if ($commission->partner_id && $commission->partner_commission_amount > 0) {
                $partner = $commission->partner;
                $invoice = Invoice::create([
                    'invoice_id' => app(InvoiceService::class)->generateInvoiceId(),
                    'partner_id' => $commission->partner_id,
                    'borrower_id' => $commission->borrower_id,
                    'entity_type' => Partner::class,
                    'entity_id' => $commission->partner_id,
                    'customer_name' => $partner->full_name ?? $partner->company_name,
                    'email' => $partner->email,
                    'country' => $partner->country,
                    'payment_amount' => $commission->partner_commission_amount,
                    // payment_date is a DATE column - store the calendar date.
                    'payment_date' => today(),
                    'status' => Invoice::STATUS_PAID,
                    'type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT,
                    'currency' => $commission->currency,
                    'currency_symbol' => $commission->currency_symbol,
                    'conversion_rate' => $commission->conversion_rate,
                    'note' => $data['note'] ?? null,
                    'created_by' => auth()->id(),
                ]);

                $paymentMethod = $data['partner_payment_method'];
                
                $payment = new Payment();
                $payment->createRecord([
                    'partner_id' => $commission->partner_id,
                    'borrower_id' => $commission->borrower_id,
                    'invoice_id' => $invoice->id,
                    'entity_type' => Partner::class,
                    'entity_id' => $commission->partner_id,
                    'amount' => $commission->partner_commission_amount,
                    'currency' => $commission->currency,
                    'currency_symbol' => $commission->currency_symbol,
                    'conversion_rate' => $commission->conversion_rate,
                    'payment_from' => $paymentMethod,
                    'payment_type' => $paymentMethod,
                    'status' => Payment::STATUS_SUCCEEDED,
                    'transaction_date' => now(),
                    'created_by' => auth()->id(),
                ]);
            }

            $commission->update([
                'payment_status' => Commission::PAYMENT_STATUS_PAID,
                'commission_status' => Commission::STATUS_COMPLETED,
                'payout_date' => now(),
            ]);

            // Inside the transaction: the bell and the queued email both wait
            // for the commit below, so a rolled-back payout announces nothing.
            if (isset($invoice) && isset($partner)) {
                app(Notifier::class)->send('partner.commission.paid', $partner, [
                    'id' => $commission->id,
                    'amount' => ($commission->currency ?: 'USD') . ' ' . number_format((float) $commission->partner_commission_amount, 2),
                ], mail: $partner->email
                    ? (new CommissionPayoutMail($commission, $invoice, $data['note'] ?? null))->to($partner->email)
                    : null);
            }

            DB::commit();

            return ApiResponse::success(
                new CommissionResource($commission->fresh()),
                __('application.commission_payout_processed_successfully')
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('❌ ' . __('application.error_processing_payout'), [
                'error' => $e->getMessage(),
                'commission_id' => $commissionId,
            ]);

            throw $e;
        }
    }
}
 