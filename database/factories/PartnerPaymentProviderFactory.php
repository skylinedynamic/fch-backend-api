<?php

namespace Database\Factories;

use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PartnerPaymentProvider;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerPaymentProvider>
 */
class PartnerPaymentProviderFactory extends Factory
{
    protected $model = PartnerPaymentProvider::class;

    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'payment_provider_id' => PaymentProvider::factory()->stripe(),
            'status' => true,
            'is_default' => true,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'configuration' => [
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
                'sandbox' => [
                    'secret_key' => 'sk_test_'.fake()->uuid(),
                    'publishable_key' => 'pk_test_'.fake()->uuid(),
                ],
                'production' => [
                    'secret_key' => 'sk_live_'.fake()->uuid(),
                    'publishable_key' => 'pk_live_'.fake()->uuid(),
                ],
            ],
        ];
    }

    public function production(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'configuration' => array_merge(is_array($attributes['configuration'] ?? null) ? $attributes['configuration'] : [], [
                'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            ]),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
