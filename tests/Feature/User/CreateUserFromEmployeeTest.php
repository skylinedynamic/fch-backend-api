<?php

namespace Tests\Feature\User;

use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every user a partner adds from the Users screen is the login of one of its
 * own employees: the employee picker only offers employees that have no user
 * yet, and creating a user records which employee it belongs to.
 */
class CreateUserFromEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private Partner $otherPartner;
    private User $admin;
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->makePartner('partner@example.test');
        $this->otherPartner = $this->makePartner('other@example.test');

        $this->admin = User::create([
            'name' => 'Partner Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::PARTNER,
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'status' => User::STATUS['active'],
        ]);

        $this->role = Role::create(['name' => 'Staff', 'partner_id' => $this->partner->id]);

        Passport::actingAs($this->admin, [], 'api');
    }

    private function makePartner(string $email): Partner
    {
        return Partner::create([
            'is_host' => true, // keeps seat-limit billing out of these tests
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => $email,
            'full_name' => $email,
            'email' => $email,
        ]);
    }

    private function makeEmployee(Partner $partner, string $first, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'partner_id' => $partner->id,
            'first_name' => "$first Doe", // first_name holds the whole name
            'email' => strtolower($first) . '@example.test',
            'phone_number_1' => '(555) 234-5678',
        ], $attributes));
    }

    private function linkUser(Employee $employee): User
    {
        return User::create([
            'name' => $employee->full_name,
            'email' => 'linked-' . $employee->id . '@example.test',
            'password' => bcrypt('secret'),
            'partner_id' => $employee->partner_id,
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
        ]);
    }

    private function userPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane.user@example.test',
            'phone_number' => '(555) 200-1111',
            'password' => 'secret123',
            'role_id' => $this->role->id,
        ], $overrides);
    }

    #[Test]
    public function it_lists_only_the_partners_employees_that_have_no_user_yet(): void
    {
        $free = $this->makeEmployee($this->partner, 'Free');
        $this->linkUser($this->makeEmployee($this->partner, 'Linked'));
        $this->makeEmployee($this->otherPartner, 'Foreign');

        $response = $this->getJson('/api/v1/common/employees-available-for-user');

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $free->id)
            ->assertJsonPath('data.0.first_name', 'Free Doe')
            ->assertJsonPath('data.0.full_name', 'Free Doe')
            ->assertJsonPath('data.0.email', 'free@example.test')
            ->assertJsonPath('data.0.phone_number_1', '(555) 234-5678');
    }

    #[Test]
    public function an_employee_whose_user_was_deleted_is_available_again(): void
    {
        $employee = $this->makeEmployee($this->partner, 'Former');
        $this->linkUser($employee)->delete();

        $this->getJson('/api/v1/common/employees-available-for-user')
            ->assertOk()
            ->assertJsonPath('data.0.id', $employee->id);
    }

    #[Test]
    public function creating_a_user_links_it_to_the_selected_employee(): void
    {
        $employee = $this->makeEmployee($this->partner, 'Jane');

        $this->postJson('/api/v1/common/users', $this->userPayload(['employee_id' => $employee->id]))
            ->assertOk()
            ->assertJsonPath('status', true);

        $user = User::where('email', 'jane.user@example.test')->firstOrFail();
        $this->assertSame(Employee::class, $user->entity_type);
        $this->assertSame($employee->id, (int) $user->entity_id);
    }

    #[Test]
    public function an_employee_is_required_to_create_a_user(): void
    {
        $this->postJson('/api/v1/common/users', $this->userPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    #[Test]
    public function an_employee_that_already_has_a_user_cannot_get_another(): void
    {
        $employee = $this->makeEmployee($this->partner, 'Taken');
        $this->linkUser($employee);

        $this->postJson('/api/v1/common/users', $this->userPayload(['employee_id' => $employee->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }

    #[Test]
    public function another_partners_employee_cannot_be_used(): void
    {
        $foreign = $this->makeEmployee($this->otherPartner, 'Foreign');

        $this->postJson('/api/v1/common/users', $this->userPayload(['employee_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');
    }
}
