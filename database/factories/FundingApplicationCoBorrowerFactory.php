<?php

namespace Database\Factories;

use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingApplicationCoBorrower>
 */
class FundingApplicationCoBorrowerFactory extends Factory
{
    protected $model = FundingApplicationCoBorrower::class;

    public function definition(): array
    {
        return [
            'funding_application_id' => FundingApplication::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'title_role' => 'Co-Owner',
            'ownership_percentage' => fake()->randomFloat(2, 1, 49),
            'email' => fake()->unique()->safeEmail(),
            'mobile_phone' => fake()->numerify('##########'),
            'home_phone' => fake()->numerify('##########'),
            'date_of_birth' => fake()->date('Y-m-d', '-25 years'),
            'ssn' => fake()->numerify('#########'),
            'street_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip_code' => fake()->postcode(),
            'drivers_license_number' => fake()->numerify('D#########'),
            'drivers_license_state' => fake()->stateAbbr(),
        ];
    }
}
