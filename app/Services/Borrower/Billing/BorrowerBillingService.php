<?php

namespace App\Services\Borrower\Billing;

use Exception;
use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\Payment\PaymentResource;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BorrowerBillingService
{
    /**
     * Get billing details for authenticated borrower.
     */
    public function getBillingDetails()
    {
        $user = Auth::user();

        if (!$user->entity_id || $user->entity_type !== Borrower::class) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $borrower = Borrower::select([
            'id',
            'first_name',
            'last_name',
            'email',
            'phone_number',
            'stripe_customer_id',
            'default_payment_method',
            'partner_payment_for_me',
        ])->find($user->entity_id);

        if (!$borrower) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $subscription = $borrower->latestSubscription;

        // Latest / Current Invoice
        $latestInvoice = Invoice::select([
            'id',
            'borrower_id',
            'invoice_id',
            'payment_amount',
            'status',
            'type',
            'created_at',
        ])
            ->where('entity_id', $borrower->id)
            ->where('entity_type', Borrower::class)
            ->where('subscription_id', $subscription->id)
            ->orderBy('id', 'desc')
            ->first();

        // Recent Payments
        $recentPayments = Payment::with(['invoice', 'borrower', 'partner'])
            ->where('entity_id', $borrower->id)
            ->where('entity_type', Borrower::class)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();

        // Calculate Upcoming Billing Details
        // See Subscription::nextBilling() - shared with SubscriptionDetailsResource
        // and MySubscriptionService. Previously fell back to payable_amount whenever
        // subscription_amount_per_month was empty - for a borrower who already
        // finished paying (enrollment_completed_at set, nothing recurs after that),
        // this wrongly showed the full one-time enrollment amount as still due.
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

        // Get Default Payment Method details & logo from PaymentProvider model
        $defaultProviderName = $borrower->default_payment_method ?? PaymentProvider::STRIPE;
        $paymentProvider = PaymentProvider::select(['name', 'display_name', 'logo'])
            ->where('name', $defaultProviderName)
            ->orWhere('display_name', $defaultProviderName)
            ->first();

        $providerLogoUrl = null;
        if ($paymentProvider && $paymentProvider->logo) {
            $providerLogoUrl = encodeUrl(asset($paymentProvider->logo));
        }

        $data = [
            'borrower' => [
                'id' => $borrower->id,
                'name' => $borrower->full_name,
                'email' => $borrower->email,
                'phone_number' => $borrower->phone_number,
                'stripe_card_info' => $borrower->stripe_card_info,
                'default_payment_method' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $borrower->default_payment_method ?? PaymentProvider::STRIPE,
                'partner_payment_for_me' => (bool) $borrower->partner_payment_for_me,
                'payment_provider' => [
                    'name' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $borrower->default_payment_method ?? PaymentProvider::STRIPE,
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
            'upcoming_billing' => [
                // Already rendered by toDateOnly() above. It is a calendar date -
                // never run it through toTimezone(), which would read the date-only
                // string as midnight UTC and shift it a day for viewers behind UTC.
                'next_date' => $nextDate,
                'amount' => currencyFormat($nextAmount),
                'raw_amount' => $nextAmount,
                'type' => $nextBilling['type'] ?? 'N/A',
                'enrollment_type' => $subscription?->enrollment_type ?? 'N/A',
                'status' => $subscription?->status ?? Subscription::STATUS_ACTIVE,
            ],
            'recent_payments' => PaymentResource::collection($recentPayments),
        ];

        return ApiResponse::success($data, __('borrower.billing_details_retrieved'));
    }

    /**
     * Get all payment providers.
     */
    public function getPaymentProviders()
    {
        $user = Auth::user();
        $borrower = ($user && $user->entity_type === Borrower::class) ? Borrower::find($user->entity_id) : null;
        $partnerId = $borrower?->partner_id ?? Partner::host()?->id;

        $providers = PaymentProvider::all()->map(function ($p) use ($partnerId) {
            $pubKey = null;
            if (strtolower(trim($p->name)) === strtolower(PaymentProvider::STRIPE) && $partnerId) {
                $partnerProvider = PartnerPaymentProvider::where('partner_id', $partnerId)
                    ->where('payment_provider_id', $p->id)
                    ->first();
                $pubKey = $partnerProvider?->publishableKey();
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name ?? $p->name,
                'logo' => $p->logo ? encodeUrl(asset($p->logo)) : null,
                'publishable_key' => $pubKey,
                'status' => $p->status ?? 1,
            ];
        });

        return ApiResponse::success($providers, __('borrower.payment_providers_retrieved'));
    }

    /**
     * Update default payment method for borrower.
     */
    public function updateDefaultPaymentMethod(array $data)
    {
        $user = Auth::user();

        if (!$user->entity_id || $user->entity_type !== Borrower::class) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $borrower = Borrower::find($user->entity_id);
        if (!$borrower) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $providerName = $data['provider_name'] ?? null;
        if (!$providerName) {
            return ApiResponse::validationError(['provider_name' => [__('borrower.provider_name_required')]]);
        }

        $isStripe = strtolower(trim($providerName)) === strtolower(PaymentProvider::STRIPE) || $providerName === PaymentProvider::STRIPE;

        if ($isStripe) {
            $partnerId = $borrower->partner_id ?? Partner::host()?->id;
            $paymentMode = $partnerId
                ? app(Payment::class)->configureStripeForPartner($partnerId)->payment_mode
                : null;

            $paymentMethodId = $data['payment_method_id'] ?? null;
            $existingCustomerData = $borrower->stripe_customer_id;

            $stripeCustId = null;
            $cardDetails = ['name' => null, 'card_no' => null];

            if ($paymentMethodId) {
                try {
                    $customerService = app(StripeCustomerService::class);
                    $stripeCustId = $customerService->resolveCustomer(
                        $borrower->email,
                        $borrower->full_name ?? 'Borrower',
                        $existingCustomerData,
                        $paymentMode
                    );
                    $customerService->setDefaultPaymentMethod($stripeCustId, $paymentMethodId);
                    $cardDetails = $customerService->fetchCardDetails($paymentMethodId);
                } catch (Exception $e) {
                    Log::error('[UpdateDefaultPaymentMethod] Stripe Error: ' . $e->getMessage());
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

            $borrower->update([
                'default_payment_method' => PaymentProvider::STRIPE,
                'stripe_customer_id' => $stripeCustId ? [
                    'customer_id' => $stripeCustId,
                    'name' => $cardDetails['name'],
                    'card_no' => $cardDetails['card_no'],
                    'payment_mode' => $paymentMode,
                ] : null,
            ]);
        } else {
            // For other payment providers, set provider name & make stripe_customer_id null
            $borrower->update([
                'default_payment_method' => $providerName,
                'stripe_customer_id' => null,
            ]);
        }

        $borrower->refresh();

        $paymentProvider = PaymentProvider::where('name', $borrower->default_payment_method)
            ->orWhere('display_name', $borrower->default_payment_method)
            ->first();

        $providerLogoUrl = null;
        if ($paymentProvider && $paymentProvider->logo) {
            $providerLogoUrl = encodeUrl(asset($paymentProvider->logo));
        }

        return ApiResponse::success([
            'default_payment_method' => $borrower->default_payment_method,
            'stripe_card_info' => $borrower->stripe_card_info,
            'payment_provider' => [
                'name' => $paymentProvider?->display_name ?? $paymentProvider?->name ?? $borrower->default_payment_method,
                'logo' => $providerLogoUrl,
            ],
        ], __('borrower.default_payment_method_updated'));
    }

    /**
     * Cancel borrower subscription.
     */
    public function cancelSubscription(array $data)
    {
        $user = Auth::user();

        if (!$user->entity_id || $user->entity_type !== Borrower::class) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $borrower = Borrower::find($user->entity_id);
        if (!$borrower) {
            return ApiResponse::error(__('borrower.borrower_not_found'), 404);
        }

        $subscription = Subscription::where('entity_id', $borrower->id)
            ->where('entity_type', Borrower::class)
            ->orderBy('id', 'desc')
            ->first();

        if (!$subscription) {
            return ApiResponse::error(__('borrower.subscription_not_found'), 404);
        }

        if (strtolower($subscription->status) === strtolower(Subscription::STATUS_CANCELLED)) {
            return ApiResponse::error(__('borrower.subscription_already_cancelled'), 400);
        }

        $subscription->status = Subscription::STATUS_CANCELLED;
        $subscription->save();

        Log::info("Borrower ID {$borrower->id} cancelled subscription ID {$subscription->id}");

        return ApiResponse::success(null, __('borrower.subscription_cancelled'));
    }
}
