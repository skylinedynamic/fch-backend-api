<?php

namespace Database\Factories;

use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentMethodBankDetail;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethodBankDetail>
 */
class PaymentMethodBankDetailFactory extends Factory
{
    protected $model = PaymentMethodBankDetail::class;

    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'bank_name' => fake()->company().' Bank',
            'account_name' => fake()->name(),
            'account_number' => fake()->numerify('##########'),
            'routing_number' => fake()->numerify('#########'),
            'instructions' => fake()->sentence(),
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'is_default' => true,
            'status' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
