<?php

namespace App\Services\FCH\Subscription;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Subscription\Subscription;
use App\Services\GlobalPayment\PaymentMailService;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Resume a cancelled subscription, on the plan it was on (BUG-069). Used by
 * the borrower's Billing Details and the partner's My Subscription.
 *
 *  - Nothing left to bill (a borrower who paid in full): active again at once.
 *  - Before the end date: active again at once - the period is already paid
 *    for, and an invoice still open is charged by the cron as usual.
 *  - After the end date: the subscription stays cancelled until a bill is
 *    paid - the one still open, or the next one, raised here. Paying it makes
 *    it active (SubscriptionService::handleSuccessfulPayment).
 *
 * Becoming active, here or on that payment, tells the subscriber, the host
 * and a client's partner (PaymentMailService::sendSubscriptionReactivated).
 */
class SubscriptionResumeService
{
    /** Active again: nothing to pay first. */
    public const RESULT_ACTIVE = 'active';

    /** Still cancelled until data.invoice_id is paid. */
    public const RESULT_PAYMENT_REQUIRED = 'payment_required';

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly PaymentMailService $paymentMailService,
    ) {
    }

    public function resume(?Subscription $subscription): JsonResponse
    {
        if (!$subscription) {
            return ApiResponse::error(__('subscription.resume.not_found'), 404);
        }

        if ($subscription->status !== Subscription::STATUS_CANCELLED) {
            return ApiResponse::error(__('subscription.resume.not_cancelled'), 400);
        }

        if ($this->nothingLeftToBill($subscription) || $this->periodStillRunning($subscription)) {
            $subscription->update(['status' => Subscription::STATUS_ACTIVE]);

            Log::info(__('subscription.resume.resumed_log'), ['subscription_id' => $subscription->id]);
            $this->paymentMailService->sendSubscriptionReactivated($subscription->fresh());

            return ApiResponse::success([
                'status' => self::RESULT_ACTIVE,
                'invoice_id' => null,
            ], __('subscription.resume.active'));
        }

        $invoice = Invoice::where('subscription_id', $subscription->id)->openCycle()->oldest('id')->first()
            ?? $this->subscriptionService->raiseResumeInvoice($subscription);

        if (!$invoice) {
            return ApiResponse::error(__('subscription.resume.nothing_to_bill'), 422);
        }

        return ApiResponse::success([
            'status' => self::RESULT_PAYMENT_REQUIRED,
            'invoice_id' => $invoice->id,
        ], __('subscription.resume.payment_required'));
    }

    /** A borrower who has paid the whole plan owes nothing more. */
    private function nothingLeftToBill(Subscription $subscription): bool
    {
        return $subscription->entity_type === Borrower::class
            && $subscription->current_phase === Subscription::PHASE_COMPLETED;
    }

    /** The period paid for has not ended (today is still within it). */
    private function periodStillRunning(Subscription $subscription): bool
    {
        $end = $subscription->subscription_end_date;

        return $end !== null && $end->copy()->startOfDay()->gte(today());
    }
}
