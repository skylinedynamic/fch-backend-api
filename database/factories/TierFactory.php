<?php

namespace Database\Factories;

use App\Models\CreditBuilder\Tier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tier>
 */
class TierFactory extends Factory
{
    protected $model = Tier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'title' => fake()->words(2, true),
            'code' => fake()->unique()->lexify('TIER-????'),
            'subtitle' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => 'active',
            'order' => 1,
            'has_vendor' => false,
            'task_to_complete' => 0,
        ];
    }
}
