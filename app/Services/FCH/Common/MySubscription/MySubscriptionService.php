<?php

namespace App\Services\FCH\Common\MySubscription;

use Exception;
use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\Common\Payment\PaymentResource;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MySubscriptionService
{
    /**
     * Get subscription & billing details for authenticated non-host partner.
     */
    public function getSubscriptionDetails()
    {
        $user = Auth::user();
        $partnerId = $user->partner_id ?? ($user->entity_type === Partner::class ? $user->entity_id : null);

        if (!$partnerId && $user->email) {
            $partner = Partner::where('email', $user->email)->first();
            $partnerId = $partner?->id;
        }

        if (!$partnerId) {
            return ApiResponse::error(__('partner.partner_not_found'), 404);
        }

        $partner = Partner::select([
            'id',
            'full_name',
            'company_name',
            'email',
            'phone_number',
            'stripe_customer_id',
            'default_payment_method',
            'is_host',
        ])->find($partnerId);

        if (!$partner) {
            return ApiResponse::error(__('partner.partner_not_found'), 404);
        }

        if ((int) $partner->is_host === 1) {
            return ApiResponse::error(__('partner.my_subscription_non_host_only'), 403);
        }

        // Partner's latest subscription
        $subscription = Subscription::with(['enrollmentPhase', 'platformFeePhase', 'partnerPlan', 'entity', 'partner', 'borrower'])
            ->where('entity_type', Partner::class)
            ->where('entity_id', $partner->id)
            ->orderBy('id', 'desc')
            ->first();

        // Latest / Current Invoice related to partner's subscription
        $latestInvoiceQuery = Invoice::select([
            'id',
            'partner_id',
            'subscription_id',
            'invoice_id',
            'payment_amount',
            'status',
            'type',
            'created_at',
        ])
            ->where('entity_id', $partner->id)
            ->where('entity_type', Partner::class);

        if ($subscription) {
            $latestInvoiceQuery->where(function ($q) use ($subscription) {
                $q->where('subscription_id', $subscription->id)
                    ->orWhereNull('subscription_id');
            });
        }

        $latestInvoice = $latestInvoiceQuery->orderBy('id', 'desc')->first();

        // Recent Payments related to partner's subscription
        $recentPaymentsQuery = Payment::with(['invoice', 'partner', 'borrower', 'entity'])
            ->where('entity_id', $partner->id)
            ->where('entity_type', Partner::class);

        if ($subscription) {
            $recentPaymentsQuery->where(function ($q) use ($subscription) {
                $q->whereHas('invoice', function ($inv) use ($subscription) {
                    $inv->where('subscription_id', $subscription->id);
                })->orWhereNull('invoice_id');
            });
        }

        $recentPayments = $recentPaymentsQuery->orderBy('id', 'desc')->limit(10)->get();

        // Calculate Upcoming Billing Details for subscription
        $nextDate = $subscription?->next_installment_date?->toDateString() ?? $subscription?->subscription_end_date?->toDateString();
        $nextAmount = $subscription?->subscription_amount_per_month ?? $subscription?->payable_amount ?? 0;

        // Determine Subscription Completion Status
        $isCompleted = false;
        if ($subscription) {
            $hasCompletedDate = !empty($subscription->enrollment_completed_at);
            $installmentsPaid = (int) ($subscription->installments_paid ?? 0);
            $installmentCount = (int) ($subscription->installment_count ?? 0);

            if ($installmentCount <= 1) {
                $isCompleted = $hasCompletedDate;
            } else {
                $isCompleted = $hasCompletedDate && ($installmentCount > 0 && $installmentsPaid >= $installmentCount);
            }

            if (
                !$isCompleted && in_array(strtolower($subscription->status ?? ''), [
                    strtolower(Subscription::STATUS_EXPIRED),
                    strtolower(Subscription::STATUS_COMPLETED),
                ])
            ) {
                $isCompleted = true;
            }
        }

        // Get Default Payment Method details & logo
        $defaultProviderName = $partner->default_payment_method ?? PaymentProvider::STRIPE;
        $paymentProvider = PaymentProvider::select(['name', 'display_name', 'logo'])
            ->where('name', $defaultProviderName)
            ->orWhere('display_name', $defaultProviderName)
            ->first();

        $providerLogoUrl = null;
        if ($paymentProvider && $paymentProvider->logo) {
            $providerLogoUrl = encodeUrl(asset($paymentProvider->logo));
        }

        $data = [
            'partner' => [
                'id' => $partner->id,
                'name' => $partner->full_name,
                'company_name' => $partner->company_name,
                'email' => $partner->email,
                'phone_number' => $partner->phone_number,
                'stripe_card_info' => $partner->stripe_card_info,
                'default_payment_method' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $partner->default_payment_method ?? PaymentProvider::STRIPE,
                'payment_provider' => [
                    'name' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $partner->default_payment_method ?? PaymentProvider::STRIPE,
                    'logo' => $providerLogoUrl,
                ],
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'enrollment_type' => $subscription->enrollment_type,
                'status' => $subscription->status,
                'total_subscription_amount' => currencyFormat($subscription->total_subscription_amount),
                'payable_amount' => currencyFormat($subscription->payable_amount),
                'down_payment' => currencyFormat($subscription->down_payment),
                'subscription_amount_per_month' => currencyFormat($subscription->subscription_amount_per_month),
                'installment_count' => (int) $subscription->installment_count,
                'installments_paid' => (int) ($subscription->installments_paid ?? 0),
                'subscription_start_date' => toTimezone($subscription->subscription_start_date),
                'subscription_end_date' => toTimezone($subscription->subscription_end_date),
                'enrollment_completed_at' => toTimezone($subscription->enrollment_completed_at),
                'is_completed' => $isCompleted,
            ] : null,
            'recent_bill' => $latestInvoice ? [
                'id' => $latestInvoice->id,
                'invoice_id' => $latestInvoice->invoice_id,
                'amount' => currencyFormat($latestInvoice->payment_amount),
                'raw_amount' => $latestInvoice->payment_amount,
                'status' => $latestInvoice->status,
                'type' => $latestInvoice->type,
                'created_at' => toTimezone($latestInvoice->created_at),
            ] : null,
            'upcoming_billing' => $subscription ? [
                'next_date' => toTimezone($nextDate),
                'amount' => currencyFormat($nextAmount),
                'raw_amount' => $nextAmount,
                'enrollment_type' => $subscription->enrollment_type ?? 'N/A',
                'status' => $subscription->status ?? Subscription::STATUS_ACTIVE,
            ] : null,
            'recent_payments' => PaymentResource::collection($recentPayments),
        ];

        return ApiResponse::success($data, __('partner.my_subscription_retrieved'));
    }

    /**
     * Update default payment method for non-host partner.
     */
    public function updateDefaultPaymentMethod(array $data)
    {
        $user = Auth::user();
        $partnerId = $user->partner_id ?? ($user->entity_type === Partner::class ? $user->entity_id : null);

        if (!$partnerId && $user->email) {
            $partner = Partner::where('email', $user->email)->first();
            $partnerId = $partner?->id;
        }

        $partner = Partner::find($partnerId);
        if (!$partner) {
            return ApiResponse::error(__('partner.partner_not_found'), 404);
        }

        $providerName = $data['provider_name'] ?? null;
        if (!$providerName) {
            return ApiResponse::validationError(['provider_name' => [__('partner.provider_name_required')]]);
        }

        $isStripe = strtolower(trim($providerName)) === strtolower(PaymentProvider::STRIPE);

        if ($isStripe) {
            $hostPartnerId = Partner::host()?->id;
            if ($hostPartnerId) {
                app(Payment::class)->configureStripeForPartner($hostPartnerId);
            }

            $paymentMethodId = $data['payment_method_id'] ?? null;
            $existingCustomerData = $partner->stripe_customer_id;

            $stripeCustId = null;
            $cardDetails = ['name' => null, 'card_no' => null];

            if ($paymentMethodId) {
                try {
                    $customerService = app(StripeCustomerService::class);
                    $stripeCustId = $customerService->resolveCustomer(
                        $partner->email,
                        $partner->full_name ?? 'Partner',
                        $existingCustomerData
                    );
                    $customerService->setDefaultPaymentMethod($stripeCustId, $paymentMethodId);
                    $cardDetails = $customerService->fetchCardDetails($paymentMethodId);
                } catch (Exception $e) {
                    Log::error('[PartnerUpdateDefaultPaymentMethod] Stripe Error: ' . $e->getMessage());
                }
            } else {
                $stripeCustId = is_array($existingCustomerData)
                    ? ($existingCustomerData['customer_id'] ?? null)
                    : $existingCustomerData;
                $cardDetails = [
                    'name' => is_array($existingCustomerData) ? ($existingCustomerData['name'] ?? null) : null,
                    'card_no' => is_array($existingCustomerData) ? ($existingCustomerData['card_no'] ?? null) : null,
                ];
            }

            $partner->update([
                'default_payment_method' => PaymentProvider::STRIPE,
                'stripe_customer_id' => $stripeCustId ? [
                    'customer_id' => $stripeCustId,
                    'name' => $cardDetails['name'],
                    'card_no' => $cardDetails['card_no'],
                ] : null,
            ]);
        } else {
            $partner->update([
                'default_payment_method' => $providerName,
                'stripe_customer_id' => null,
            ]);
        }

        $partner->refresh();

        $paymentProvider = PaymentProvider::where('name', $partner->default_payment_method)
            ->orWhere('display_name', $partner->default_payment_method)
            ->first();

        $providerLogoUrl = null;
        if ($paymentProvider && $paymentProvider->logo) {
            $providerLogoUrl = encodeUrl(asset($paymentProvider->logo));
        }

        return ApiResponse::success([
            'default_payment_method' => $partner->default_payment_method,
            'stripe_card_info' => $partner->stripe_card_info,
            'payment_provider' => [
                'name' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $partner->default_payment_method,
                'logo' => $providerLogoUrl,
            ],
        ], __('partner.default_payment_method_updated'));
    }
}
