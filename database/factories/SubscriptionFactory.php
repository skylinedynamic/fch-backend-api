<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $borrower = Borrower::factory();

        return [
            'entity_type' => Borrower::class,
            'entity_id' => $borrower,
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'partner_type' => 'Certified Partner (CP)',
            'enrollment_type' => Subscription::ENROLLMENT_TYPE_PAYMENT_PLAN,
            'monthly_platform_fee' => 25.00,
            'total_subscription_amount' => 500.00,
            'subscription_amount_per_month' => null,
            'borrower_fee_amount' => null,
            'payable_amount' => 500.00,
            'down_payment' => 0.00,
            'first_payment_date' => null,
            'installment_type' => 0,
            'installment_count' => 0,
            'subscription_start_date' => now()->toDateString(),
            'subscription_end_date' => now()->addMonth()->toDateString(),
            'current_cycle_invoice_id' => null,
            'status' => Subscription::STATUS_PENDING,
            'is_custom' => false,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'installments_paid' => 0,
            'enrollment_completed_at' => null,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1.00,
        ];
    }

    /**
     * Attach the subscription to a specific Borrower entity.
     */
    public function forBorrower(Borrower $borrower): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
        ]);
    }

    /**
     * Attach the subscription to a specific Partner entity.
     */
    public function forPartner(Partner $partner): static
    {
        return $this->state(fn (array $attributes) => [
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_CANCELLED,
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Subscription::STATUS_PAST_DUE,
        ]);
    }
}
