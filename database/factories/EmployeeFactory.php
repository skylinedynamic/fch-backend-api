<?php

namespace Database\Factories;

use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number_1' => fake()->numerify('##########'),
            'date_of_birth' => fake()->date(),
            'gender' => 'other',
            'department' => 'Underwriting',
            'designation' => 'Analyst',
            'job_status' => Employee::STATUS_ACTIVE,
            'joining_date' => now()->toDateString(),
            'country' => 'US',
            'country_code' => 'US',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'job_status' => Employee::STATUS_INACTIVE,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'job_status' => Employee::STATUS_SUSPENDED,
        ]);
    }
}
