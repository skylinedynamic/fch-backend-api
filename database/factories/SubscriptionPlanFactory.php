<?php

namespace Database\Factories;

use App\Models\Partner\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'partner_type' => 'Certified Partner (CP)',
            'partner_type_slug' => 'certified_partner',
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT_SLUG,
            'entity_type' => 'Partner',
            'is_partner_paying' => false,
            'total_installment_count_upto' => 1,
            'total_seat_count' => null,
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_PARTNER_ENROLLMENT,
            'total_amount' => 500.00,
            'platform_fee' => 50.00,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1.00,
            'status' => true,
        ];
    }

    public function installment(): static
    {
        return $this->state(fn (array $attributes) => [
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT,
            'enrollment_type_slug' => SubscriptionPlan::ENROLLMENT_TYPE_INSTALLMENT_SLUG,
            'total_installment_count_upto' => 3,
        ]);
    }

    public function borrowerEnrollment(): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => 'Borrower',
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_BORROWER_ENROLLMENT,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
