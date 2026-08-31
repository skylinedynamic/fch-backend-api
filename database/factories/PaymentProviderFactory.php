<?php

namespace Database\Factories;

use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentProvider>
 */
class PaymentProviderFactory extends Factory
{
    protected $model = PaymentProvider::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Gateway',
            'display_name' => fake()->company(),
            'logo' => null,
            'status' => true,
        ];
    }

    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => PaymentProvider::STRIPE,
            'display_name' => 'Stripe',
        ]);
    }

    public function bankTransfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => PaymentProvider::BANK_TRANSFER,
            'display_name' => 'Bank Transfer',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
