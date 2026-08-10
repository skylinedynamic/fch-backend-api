<?php

namespace App\Services\Borrower\Billing;

use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\Borrower\Common\AdvisorResource;

class BorrowerBillingService
{

    public function getBillingDetails()
    {
        $user = Auth::user();

        if (!$user->entity_id || $user->entity_type !== Borrower::class) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $borrower = Borrower::with(['partner', 'latestSubscription.enrollmentPhase', 'latestSubscription.platformFeePhase'])->find($user->entity_id);

        if (!$borrower) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $subscription = $borrower->latestSubscription;
        $phase = $subscription?->enrollmentPhase ?? $subscription?->platformFeePhase;

        // Latest / Current Invoice
        $latestInvoice = \App\Models\Invoice\Invoice::where('borrower_id', $borrower->id)
            ->where('entity_type', Borrower::class)
            ->orderBy('id', 'desc')
            ->first();

        // Recent Payments
        $recentPayments = \App\Models\Payment\Payment::where('borrower_id', $borrower->id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        // Calculate Upcoming Billing Details
        $nextDate = $phase?->next_installment_date?->toDateString() ?? $phase?->next_platform_fee_date?->toDateString() ?? $subscription?->next_installment_date?->toDateString();
        $nextAmount = $subscription?->payable_amount ?? $subscription?->subscription_amount_per_month ?? 0;

        $data = [
            'borrower' => [
                'id' => $borrower->id,
                'name' => $borrower->full_name,
                'email' => $borrower->email,
                'phone_number' => $borrower->phone_number,
                'stripe_customer_id' => $borrower->stripe_customer_id,
                'default_payment_method' => $borrower->default_payment_method,
                'partner_payment_for_me' => (bool) $borrower->partner_payment_for_me,
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'enrollment_type' => $subscription->enrollment_type,
                'status' => $subscription->status,
                'total_subscription_amount' => $subscription->total_subscription_amount,
                'payable_amount' => $subscription->payable_amount,
                'down_payment' => $subscription->down_payment,
                'installment_count' => $subscription->installment_count,
                'subscription_start_date' => $subscription->subscription_start_date?->toDateString(),
                'subscription_end_date' => $subscription->subscription_end_date?->toDateString(),
            ] : null,
            'recent_bill' => $latestInvoice ? [
                'id' => $latestInvoice->id,
                'invoice_id' => $latestInvoice->invoice_id,
                'amount' => $latestInvoice->payment_amount,
                'status' => strtoupper($latestInvoice->status),
                'type' => $latestInvoice->type,
                'created_at' => $latestInvoice->created_at?->toDateTimeString(),
            ] : null,
            'upcoming_billing' => [
                'next_date' => $nextDate,
                'amount' => $nextAmount,
                'enrollment_type' => $subscription?->enrollment_type ?? 'N/A',
                'status' => $subscription?->status ?? 'ACTIVE',
            ],
            'recent_payments' => \App\Http\Resources\Borrower\Payment\PaymentResource::collection($recentPayments),
        ];

        return ApiResponse::success($data, __('borrower.billing_details_retrieved'));
    }
}
