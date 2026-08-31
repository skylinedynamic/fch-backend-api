<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Defaults to an Admin user (no partner_id, no entity) since that's the
     * simplest valid login-able user; use the ->borrower()/->partner()/
     * ->employee() states for the other user_types.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->numerify('##########'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'user_type' => User::ADMIN,
            'status' => User::STATUS['active'],
            'password' => static::$password ??= Hash::make('password'),
            'partner_id' => null,
            'entity_type' => null,
            'entity_id' => null,
            'is_paid' => false,
            'is_refresh' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => User::ADMIN,
            'partner_id' => null,
            'entity_type' => null,
            'entity_id' => null,
        ]);
    }

    /**
     * A Partner-type user, linked to a real Partner entity (created if not passed).
     */
    public function partner(?Partner $partner = null): static
    {
        return $this->state(function (array $attributes) use ($partner) {
            $partner ??= Partner::factory()->create();

            return [
                'user_type' => User::PARTNER,
                'partner_id' => $partner->id,
                'entity_type' => Partner::class,
                'entity_id' => $partner->id,
            ];
        });
    }

    /**
     * A Borrower-type user, linked to a real Borrower entity (created if not passed).
     */
    public function borrower(?Borrower $borrower = null): static
    {
        return $this->state(function (array $attributes) use ($borrower) {
            $borrower ??= Borrower::factory()->create();

            return [
                'user_type' => User::BORROWER,
                'partner_id' => $borrower->partner_id,
                'entity_type' => Borrower::class,
                'entity_id' => $borrower->id,
            ];
        });
    }

    /**
     * An Employee-type user, linked to a real Employee entity (created if not passed).
     */
    public function employee(?Employee $employee = null): static
    {
        return $this->state(function (array $attributes) use ($employee) {
            $employee ??= Employee::factory()->create();

            return [
                'user_type' => User::EMPLOYEE,
                'partner_id' => $employee->partner_id,
                'entity_type' => Employee::class,
                'entity_id' => $employee->id,
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS['inactive'],
        ]);
    }
}
