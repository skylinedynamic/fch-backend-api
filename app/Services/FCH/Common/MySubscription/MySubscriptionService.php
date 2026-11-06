<?php

namespace App\Services\FCH\Common\MySubscription;

use Exception;
use App\Helpers\ApiResponse;
use App\Services\FCH\Subscription\SubscriptionResumeService;
use App\Http\Resources\FCH\Common\Payment\PaymentResource;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MySubscriptionService
{
    /**
     * The caller's partner when the caller is that partner's own login, or the
     * refusal. My Subscription is the partner's account with the host - its
     * staff may not read, change or cancel it (findings O23), and the host,
     * which pays no one, has none.
     */
    private function ownPartnerOrRefusal(): Partner|JsonResponse
    {
        $user = Auth::user();
        $partner = $user?->partner;

        if (!$partner) {
            return ApiResponse::error(__('partner.partner_not_found'), 404);
        }

        if ((int) $partner->is_host === 1) {
            return ApiResponse::error(__('partner.my_subscription_non_host_only'), 403);
        }

        if ($user->entity_type !== Partner::class || (int) $user->entity_id !== (int) $partner->id) {
            return ApiResponse::error(__('partner.my_subscription_owner_only'), 403);
        }

        return $partner;
    }

    /**
     * The default payment method to show: the recorded one, or Stripe for a
     * card saved before defaults were recorded. None once it was removed.
     */
    private function shownDefaultProvider(Partner $partner): ?string
    {
        return $partner->default_payment_method
            ?? ($partner->stripe_customer_id_raw ? PaymentProvider::STRIPE : null);
    }

    /**
     * Get subscription & billing details for authenticated non-host partner.
     */
    public function getSubscriptionDetails()
    {
        $partner = $this->ownPartnerOrRefusal();
        if ($partner instanceof JsonResponse) {
            return $partner;
        }

        // Partner's latest subscription
        $subscription = Subscription::with(['partnerPlan', 'entity', 'partner', 'borrower'])
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

        // Recent Payments: what the partner paid the host - its plan, its staff
        // seats and the borrower fee for each client it activated
        // (Invoice::PARTNER_BILL_TYPES). A commission paid TO the partner is not
        // one of its payments. An untyped invoice predates the type column and
        // is a plan charge.
        $recentPaymentsQuery = Payment::with(['invoice', 'partner', 'borrower', 'entity'])
            ->where('entity_id', $partner->id)
            ->where('entity_type', Partner::class)
            ->whereHas('invoice', function ($invoice) {
                $invoice->where(function ($type) {
                    $type->whereIn('type', Invoice::PARTNER_BILL_TYPES)->orWhereNull('type');
                });
            });

        $recentPayments = $recentPaymentsQuery->orderBy('id', 'desc')->limit(10)->get();

        // Calculate Upcoming Billing Details for subscription.
        // See Subscription::nextBilling() - shared with SubscriptionDetailsResource
        // (the Subscription Details page) so both pages agree on what's due next.
        // Previously this fell back to payable_amount whenever
        // subscription_amount_per_month was empty, which is only correct during
        // enrollment - once enrollment_completed_at is set, a partner owes the
        // recurring monthly_platform_fee, not the one-time enrollment total again.
        $nextBilling = $subscription?->nextBilling() ?? ['date' => null, 'type' => null, 'amount' => 0];
        $nextDate = toDateOnly($nextBilling['date']);
        $nextAmount = $nextBilling['amount'] ?? 0;

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
        $defaultProviderName = $this->shownDefaultProvider($partner);
        $paymentProvider = $defaultProviderName
            ? PaymentProvider::select(['name', 'display_name', 'logo'])
                ->where('name', $defaultProviderName)
                ->orWhere('display_name', $defaultProviderName)
                ->first()
            : null;

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
                'default_payment_method' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $defaultProviderName,
                'payment_provider' => $defaultProviderName ? [
                    'name' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $defaultProviderName,
                    'logo' => $providerLogoUrl,
                ] : null,
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'enrollment_type' => $subscription->enrollment_type,
                'status' => $subscription->status,
                'total_subscription_amount' => currencyFormat($subscription->total_subscription_amount),
                'payable_amount' => currencyFormat($subscription->payable_amount),
                'down_payment' => currencyFormat($subscription->down_payment),
                'subscription_amount_per_month' => currencyFormat($subscription->subscription_amount_per_month),
                // The last installment, when it takes up the monthly rounding (BUG-049).
                'final_installment_amount' => currencyFormat($subscription->adjustedFinalInstallmentAmount()),
                // The recurring platform fee, distinct from subscription_amount_per_month
                // (which is the installment/enrollment amount and is null once
                // enrollment is complete - it was never the platform fee).
                'monthly_platform_fee' => currencyFormat($subscription->monthly_platform_fee),
                'installment_count' => (int) $subscription->installment_count,
                'installments_paid' => (int) ($subscription->installments_paid ?? 0),
                'subscription_start_date' => toDateOnly($subscription->subscription_start_date),
                'subscription_end_date' => toDateOnly($subscription->subscription_end_date),
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
                // Already rendered by toDateOnly() above. It is a calendar date -
                // never run it through toTimezone(), which would read the date-only
                // string as midnight UTC and shift it a day for viewers behind UTC.
                'next_date' => $nextDate,
                'amount' => currencyFormat($nextAmount),
                'raw_amount' => $nextAmount,
                'type' => $nextBilling['type'] ?? 'N/A',
                'enrollment_type' => $subscription->enrollment_type ?? 'N/A',
                'current_phase' => $subscription->current_phase
                    ? ucwords(str_replace('_', ' ', $subscription->current_phase))
                    : 'N/A',
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
        $partner = $this->ownPartnerOrRefusal();
        if ($partner instanceof JsonResponse) {
            return $partner;
        }

        $providerName = $data['provider_name'] ?? null;
        if (!$providerName) {
            return ApiResponse::validationError(['provider_name' => [__('partner.provider_name_required')]]);
        }

        // A partner pays the host, so its default must be a gateway the host offers.
        if (!PaymentProvider::isOfferedBy($providerName, Partner::host()?->id)) {
            return ApiResponse::validationError(['provider_name' => [__('payment_method.provider_not_offered')]]);
        }

        $isStripe = strtolower(trim($providerName)) === strtolower(PaymentProvider::STRIPE);

        if ($isStripe) {
            $hostPartnerId = Partner::host()?->id;
            $paymentMode = $hostPartnerId
                ? app(Payment::class)->configureStripeForPartner($hostPartnerId)->payment_mode
                : null;

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
                        $existingCustomerData,
                        $paymentMode
                    );
                    $customerService->setDefaultPaymentMethod($stripeCustId, $paymentMethodId);
                    $cardDetails = $customerService->fetchCardDetails($paymentMethodId);
                } catch (Exception $e) {
                    Log::error('[PartnerUpdateDefaultPaymentMethod] Stripe Error: ' . $e->getMessage());

                    // Leave the saved card as it is rather than overwriting it
                    // with nothing.
                    return ApiResponse::error(__('partner.payment_method_update_failed', ['error' => $e->getMessage()]), 422);
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
                    'payment_mode' => $paymentMode,
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

    /**
     * Take the partner's default payment method off. Nothing is auto-charged
     * until it sets one again - the charge job needs a saved customer. Only the
     * default goes: payments stay, and nothing is removed at Stripe (the
     * customer is found again by email when it next pays by card).
     */
    public function removeDefaultPaymentMethod()
    {
        $partner = $this->ownPartnerOrRefusal();
        if ($partner instanceof JsonResponse) {
            return $partner;
        }

        $partner->update([
            'default_payment_method' => null,
            'stripe_customer_id' => null,
        ]);

        Log::info("Partner ID {$partner->id} removed its default payment method");

        return ApiResponse::success([
            'default_payment_method' => null,
            'stripe_card_info' => $partner->fresh()->stripe_card_info,
            'payment_provider' => null,
        ], __('partner.default_payment_method_removed'));
    }

    /**
     * Resume the partner's own cancelled subscription (BUG-069). Only the
     * partner's own login may, as for cancelling.
     */
    public function resumeSubscription()
    {
        $partner = $this->ownPartnerOrRefusal();
        if ($partner instanceof JsonResponse) {
            return $partner;
        }

        $subscription = Subscription::where('entity_id', $partner->id)
            ->where('entity_type', Partner::class)
            ->orderBy('id', 'desc')
            ->first();

        return app(SubscriptionResumeService::class)->resume($subscription);
    }

    /**
     * Cancel non-host partner active subscription.
     */
    public function cancelSubscription()
    {
        $partner = $this->ownPartnerOrRefusal();
        if ($partner instanceof JsonResponse) {
            return $partner;
        }

        $subscription = Subscription::where('entity_id', $partner->id)
            ->where('entity_type', Partner::class)
            ->orderBy('id', 'desc')
            ->first();

        if (!$subscription) {
            return ApiResponse::error(__('partner.subscription_not_found'), 404);
        }

        if (strtolower($subscription->status) === strtolower(Subscription::STATUS_CANCELLED)) {
            return ApiResponse::error(__('partner.subscription_already_cancelled'), 400);
        }

        $subscription->status = Subscription::STATUS_CANCELLED;
        $subscription->save();

        Log::info("Partner ID {$partner->id} cancelled subscription ID {$subscription->id}");

        return ApiResponse::success(null, __('partner.subscription_cancelled'));
    }
}
