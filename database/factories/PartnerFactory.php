<?php

namespace Database\Factories;

use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        return [
            'is_host' => false,
            'has_sign' => false,
            'full_name' => fake()->name(),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone_number' => fake()->numerify('##########'),
            'contact_person' => fake()->name(),
            'contact_person_email' => fake()->unique()->safeEmail(),
            'contact_person_phone_number' => fake()->numerify('##########'),
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'status' => Partner::STATUS_ACTIVE,
            'state' => fake()->stateAbbr(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'address_line_1' => fake()->streetAddress(),
            'country' => 'US',
            'country_code' => 'US',
            'joining_date' => now()->toDateString(),
            'is_free_trail' => false,
        ];
    }

    public function host(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_host' => true,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Partner::STATUS_SUSPENDED,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Partner::STATUS_INACTIVE,
        ]);
    }
}
