<?php

namespace Tests\Feature\User;

use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * One seat-limit rule for the Users screen (common/user-count) and for the
 * server's own check when a user or an employee's login is created:
 *
 *   the partner's subscription user_seats → else the Plan & Fee "User Seat"
 *   plan's total_seat_count for its partner type → else 3
 *
 * Defect: user-count followed that rule, but creating a user or an employee
 * login read user_seats alone. With it empty (no seat plan when the
 * subscription was made) the screen said seats were free, and Save was
 * refused with "User limit reached".
 */
class UserSeatLimitRuleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $admin;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->admin = $this->makePartnerAdmin($this->partner, ['create users', 'create employees', 'view users']);
        $this->role = Role::create(['name' => 'Staff', 'partner_id' => $this->partner->id]);
        $this->actingAsUser($this->admin);
    }

    private function seatPlan(int $seats): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'group' => SubscriptionPlan::SUBSCRIPTION_PLAN_USER_SEAT,
            'partner_type' => $this->partner->partner_type,
            'total_seat_count' => $seats,
            'total_amount' => 49,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1,
            'status' => true,
        ]);
    }

    private function subscription(?int $userSeats): Subscription
    {
        return Subscription::create([
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'partner_type' => $this->partner->partner_type,
            'installment_count' => 1,
            'payable_amount' => 0,
            'total_subscription_amount' => 0,
            'monthly_platform_fee' => 0,
            'user_seats' => $userSeats,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    private function employee(string $first): Employee
    {
        return Employee::create([
            'partner_id' => $this->partner->id,
            'first_name' => "$first Doe",
            'email' => strtolower($first) . '@example.test',
        ]);
    }

    private function createUser(string $first)
    {
        return $this->postJson('/api/v1/common/users', [
            'employee_id' => $this->employee($first)->id,
            'name' => "$first Doe",
            'email' => strtolower($first) . '.user@example.test',
            'password' => 'secret123',
            'role_id' => $this->role->id,
        ]);
    }

    /** Fills seats with employee logins, on top of the partner's own. */
    private function useSeats(int $logins): void
    {
        for ($i = 1; $i < $logins; $i++) {
            $this->makeStaffUser($this->partner);
        }
    }

    #[Test]
    public function an_empty_user_seats_falls_back_to_the_plan_on_screen_and_on_save_alike(): void
    {
        $this->seatPlan(3);
        $this->subscription(null);

        $this->getJson('/api/v1/common/user-count')->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.seat_limit', 3);

        $this->createUser('Jane')->assertOk()->assertJsonPath('status', true);
    }

    #[Test]
    public function with_no_seat_plan_either_the_limit_is_three_on_both(): void
    {
        $this->subscription(null);

        $this->getJson('/api/v1/common/user-count')->assertJsonPath('data.seat_limit', 3);

        $this->createUser('Jane')->assertOk();
        $this->createUser('John')->assertOk();
        $this->createUser('Jill')->assertJsonPath('status', false);
    }

    #[Test]
    public function the_subscriptions_own_user_seats_wins_over_the_plan(): void
    {
        $this->seatPlan(3);
        $this->subscription(5);
        $this->useSeats(4);

        $this->getJson('/api/v1/common/user-count')->assertJsonPath('data.seat_limit', 5);

        $this->createUser('Jane')->assertOk()->assertJsonPath('status', true);
        $this->createUser('John')->assertJsonPath('status', false);
    }

    #[Test]
    public function at_the_limit_a_user_is_still_refused(): void
    {
        $this->seatPlan(3);
        $this->subscription(null);
        $this->useSeats(3);

        $this->createUser('Jane')->assertJsonPath('status', false);
        $this->assertFalse(User::where('email', 'jane.user@example.test')->exists());
    }

    #[Test]
    public function an_employee_login_follows_the_same_fallback(): void
    {
        $this->seatPlan(3);
        $this->subscription(null);

        $this->postJson('/api/v1/common/employees', [
            'first_name' => 'Emma Doe',
            'email' => 'emma@example.test',
            'phone_number_1' => '(555) 234-5678',
            'country' => 'United States',
            'create_account' => true,
            'role_id' => $this->role->id,
        ])->assertJsonPath('status', true);

        $this->assertTrue(User::where('email', 'emma@example.test')->exists());
    }
}
