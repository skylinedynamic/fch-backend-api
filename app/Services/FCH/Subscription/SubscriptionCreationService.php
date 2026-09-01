<?php

namespace App\Services\FCH\Subscription;

use App\Helpers\ApiResponse;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Models\Employee\Employee;
use Log;

class SubscriptionCreationService
{
    /**
     * Whether an enrollment type is One Time Payment. Subscription.enrollment_type
     * is only ever stored as the label (SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT),
     * never the slug.
     */
    public static function isOneTimePayment(?string $enrollmentType): bool
    {
        return $enrollmentType === SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT;
    }

    /**
     * Create a pending subscription for a paid user/employee seat.
     *
     * @param string $entityType
     * @param int $entityId
     * @param Partner $partner
     * @param float|null $amount
     * @return Subscription
     */
    public function createPendingUserSeatSubscription(string $entityType, int $entityId, Partner $partner, float $amount = null): Subscription
    {

        $plan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
            ->where('partner_type', $partner->partner_type)
            ->active()
            ->first();

        if (!$plan) {
            throw new \Exception('User seat subscription plan not found');
        }

        return Subscription::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'subscription_plan_id' => $plan->id,
            'partner_type' => $partner->partner_type,
            'enrollment_type' => Subscription::ENROLLMENT_TYPE_MONTHLY,
            'monthly_platform_fee' => $amount ?? $plan->total_amount,
            'total_subscription_amount' => $amount ?? $plan->total_amount,
            'subscription_amount_per_month' => null,
            'payable_amount' => $amount ?? $plan->total_amount,
            'down_payment' => 0.00,
            'first_payment_date' => null,
            'platform_fee_start_date' => null,
            'installment_type' => 0,
            'installment_count' => null,
            'subscription_start_date' => null,
            'subscription_end_date' => null,
            'expected_end_date' => null,
            'status' => Subscription::STATUS_PENDING,
            'user_seats' => 1,
            'per_seat_amount' => $amount ?? $plan->total_amount,
            'is_custom' => false,
            'current_phase' => Subscription::PHASE_USER_SEAT,
        ]);
    }

    /**
     * Start a standard Partner/Borrower subscription.
     *
     * @param mixed $entity
     * @param mixed $plan
     * @param array $data
     * @return Subscription
     */
    public function startSubscription($entity, $plan, array $data = []): Subscription
    {
        $seat_plan = null;

        if ($entity instanceof Partner) {
            $seat_plan = SubscriptionPlan::where('group', SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT)
                ->where('partner_type', $entity->partner_type)
                ->active()
                ->first();
        }

        $data['subscription_plan_id'] = $data['subscription_plan_id'] ?? $plan?->id;
        $data['partner_type'] = $data['partner_type'] ?? $plan?->partner_type;
        $data['enrollment_type'] = $data['enrollment_type'] ?? $plan?->enrollment_type;
        $data['installment_type'] = $data['installment_type'] ?? (($plan && $plan->isInstallmentPlan()) ? 1 : 0);
        $data['installment_count'] = $data['installment_count'] ?? $plan?->total_installment_count_upto ?? 0;
        $data['monthly_platform_fee'] = $data['monthly_platform_fee'] ?? $plan?->platform_fee ?? 0;
        $data['payable_amount'] = $data['payable_amount'] ?? $plan?->total_amount ?? 0;
        $data['down_payment'] = $data['down_payment'] ?? 0;
        $data['total_subscription_amount'] = $data['total_subscription_amount'] ?? $plan?->total_amount ?? 0;


        // Calculate subscription_amount_per_month.
        // array_key_exists, not isset: callers such as EnrollmentService deliberately
        // pass null here for one-time plans, and isset() is false for an explicit
        // null — which silently overwrote that null with payable_amount.
        if (!array_key_exists('subscription_amount_per_month', $data)) {
            $totalSubAmount = $data['total_subscription_amount'] ?? 0;
            $downPayment = $data['down_payment'] ?? 0;
            $installmentCount = $data['installment_count'] ?? 0;
            $installmentType = $data['installment_type'] ?? 0;

            if ($installmentType == 1 && $installmentCount > 0) {
                // Installment plan: spread the remaining balance over the installment months
                $data['subscription_amount_per_month'] = ($totalSubAmount - $downPayment) / $installmentCount;
            } elseif ($this->isOneTimePayment($data['enrollment_type'] ?? null)) {
                // One Time Payment is settled in a single charge — there is no
                // recurring monthly amount, so this stays null rather than echoing
                // payable_amount (which reads as a monthly charge in billing views).
                $data['subscription_amount_per_month'] = null;
            } else {
                // Other non-installment plans (monthly / seat fee): the per-month
                // amount equals the payable amount. monthly_platform_fee is always 0
                // for borrowers, so fall back to payable_amount.
                $data['subscription_amount_per_month'] = $data['payable_amount'] ?? ($data['monthly_platform_fee'] ?? 0);
            }
        }

        $data['status'] = $data['status'] ?? Subscription::STATUS_PENDING;

        //only for partners
        $data['user_seats'] = $seat_plan ? $seat_plan->total_seat_count : null;
        // $data['per_seat_amount'] = $seat_plan ? $seat_plan->total_amount : null;

        $isInstallmentType = strtolower($data['enrollment_type']) == SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG;
        $hasDownPayment = ($data['down_payment'] ?? 0) > 0;

        if (!isset($data['current_phase'])) {

            if ($isInstallmentType && $hasDownPayment) {
                $data['current_phase'] = Subscription::PHASE_ENROLLMENT;
            } elseif ($isInstallmentType && !$hasDownPayment) {
                $data['current_phase'] = Subscription::PHASE_INSTALLMENT;
            } elseif (!$isInstallmentType) {
                $data['current_phase'] = Subscription::PHASE_ENROLLMENT;
            } else {
                $data['current_phase'] = Subscription::PHASE_USER_SEAT;
            }
        }

        $subscriptionFillable = (new Subscription())->getFillable();
        $subscriptionData = array_intersect_key($data, array_flip($subscriptionFillable));

        $subscription = Subscription::updateOrCreate(
            ['entity_type' => get_class($entity), 'entity_id' => $entity->id],
            $subscriptionData
        );

        return $subscription->fresh();
    }
}
