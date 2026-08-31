<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Borrower>
 */
class BorrowerFactory extends Factory
{
    protected $model = Borrower::class;

    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->numerify('##########'),
            'country' => 'US',
            'country_code' => 'US',
            'state' => fake()->stateAbbr(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'home_address' => fake()->streetAddress(),
            'date_of_birth' => fake()->date(),
            'business_name' => fake()->company(),
            'business_ein' => fake()->numerify('##-#######'),
            'currency' => 'USD',
            'status' => Borrower::STATUS_ACTIVE,
            'enrollment_type' => 'Standard',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Borrower::STATUS_SUSPENDED,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Borrower::STATUS_INACTIVE,
        ]);
    }
}
