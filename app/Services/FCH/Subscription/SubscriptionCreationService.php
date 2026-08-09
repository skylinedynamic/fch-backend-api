<?php

namespace App\Services\FCH\Subscription;

use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Models\Employee\Employee;

class SubscriptionCreationService
{
    /**
     * Create a pending subscription for a paid user/employee seat.
     *
     * @param string $entityType
     * @param int $entityId
     * @param float $amount
     * @param int $months
     * @return Subscription
     */
    public function createPendingUserSeatSubscription(string $entityType, int $entityId, float $amount, int $months): Subscription
    {
        $monthlyFee = $months > 0 ? ($amount / $months) : $amount;

        return Subscription::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'subscription_plan_id' => null,
            'partner_type' => null,
            'enrollment_type' => Subscription::ENROLLMENT_TYPE_MONTHLY,
            'monthly_platform_fee' => $monthlyFee,
            'total_subscription_amount' => $amount,
            'subscription_amount_per_month' => $monthlyFee,
            'payable_amount' => $monthlyFee,  // per-cycle amount = monthly fee
            'down_payment' => 0.00,
            'first_payment_date' => null,
            'platform_fee_start_date' => null,
            'installment_type' => 0,
            'installment_count' => $months,
            'subscription_start_date' => null,
            'subscription_end_date' => null,          // set after first payment
            'status' => Subscription::STATUS_PENDING,
            'is_custom' => false,
            'current_phase' => Subscription::PHASE_USER_SEAT,  // always user_seat
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

        // Calculate subscription_amount_per_month
        if (!isset($data['subscription_amount_per_month'])) {
            $totalSubAmount = $data['total_subscription_amount'] ?? 0;
            $downPayment = $data['down_payment'] ?? 0;
            $installmentCount = $data['installment_count'] ?? 0;
            $installmentType = $data['installment_type'] ?? 0;

            if ($installmentType == 1 && $installmentCount > 0) {
                $data['subscription_amount_per_month'] = ($totalSubAmount - $downPayment) / $installmentCount;
            } else {
                $data['subscription_amount_per_month'] = $data['monthly_platform_fee'] ?? 0;
            }
        }

        $data['status'] = $data['status'] ?? Subscription::STATUS_PENDING;

        //only for partners
        $data['user_seats'] = $seat_plan ? $seat_plan->total_seat_count : null;
        $data['per_seat_amount'] = $seat_plan ? $seat_plan->total_amount : null;

        // Determine initial current_phase and subscription_end_date
        $hasDownPayment = ($data['down_payment'] ?? 0) > 0;
        $hasInstallments = ($data['installment_count'] ?? 0) > 0 && ($data['installment_type'] ?? 0) == 1;

        if (!isset($data['current_phase'])) {
            if ($hasDownPayment) {
                // Waiting for down payment before installments start
                $data['current_phase'] = Subscription::PHASE_ENROLLMENT;
            } elseif ($hasInstallments) {
                // No down payment, installments start immediately
                $data['current_phase'] = Subscription::PHASE_INSTALLMENT;
            } else {
                // Monthly-only plan (no installments)
                $data['current_phase'] = Subscription::PHASE_PLATFORM_FEE;
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
