<?php

namespace Tests\Feature\User;

use App\Models\Borrower\Borrower;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Database\Seeders\LinkUsersToEmployeesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every user is now the login of one of its partner's employees. Users made
 * before that rule have no employee: this one-off seeder gives each of them
 * an employee record built from the user and links the user to it.
 */
#[CoversClass(LinkUsersToEmployeesSeeder::class)]
class LinkUsersToEmployeesSeederTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Partner Co',
            'full_name' => 'Pat Owner',
            'email' => 'owner@partner.test',
        ]);
    }

    private function user(string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Jane Doe',
            'email' => $email,
            'phone_number' => '(555) 123-4567',
            'password' => bcrypt('secret'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->partner->id,
            'entity_type' => null,
            'entity_id' => null,
        ], $attributes));
    }

    #[Test]
    public function a_user_without_an_employee_gets_one_built_from_the_user_and_is_linked_to_it(): void
    {
        $user = $this->user('jane@partner.test');

        $this->seed(LinkUsersToEmployeesSeeder::class);

        $user->refresh();
        $this->assertSame(Employee::class, $user->entity_type);

        $employee = Employee::findOrFail($user->entity_id);
        $this->assertSame($this->partner->id, (int) $employee->partner_id);
        $this->assertSame('Jane Doe', $employee->first_name);
        $this->assertSame('jane@partner.test', $employee->email);
        $this->assertSame('(555) 123-4567', $employee->phone_number_1);
        $this->assertSame(Employee::STATUS_ACTIVE, $employee->job_status);
        $this->assertMatchesRegularExpression('/^EMP-\d{5}$/', $employee->employee_code);
    }

    #[Test]
    public function an_inactive_user_becomes_an_inactive_employee(): void
    {
        $user = $this->user('gone@partner.test', ['status' => User::STATUS['inactive']]);

        $this->seed(LinkUsersToEmployeesSeeder::class);

        $this->assertSame(Employee::STATUS_INACTIVE, Employee::findOrFail($user->refresh()->entity_id)->job_status);
    }

    #[Test]
    public function each_new_employee_gets_its_own_code(): void
    {
        $first = $this->user('one@partner.test');
        $second = $this->user('two@partner.test');

        $this->seed(LinkUsersToEmployeesSeeder::class);

        $codes = Employee::whereIn('id', [$first->refresh()->entity_id, $second->refresh()->entity_id])->pluck('employee_code');
        $this->assertCount(2, $codes->unique());
    }

    #[Test]
    public function a_user_whose_employee_was_deleted_gets_a_new_one(): void
    {
        $old = Employee::create(['partner_id' => $this->partner->id, 'first_name' => 'Old Record']);
        $user = $this->user('relinked@partner.test', ['entity_type' => Employee::class, 'entity_id' => $old->id]);
        $old->delete();

        $this->seed(LinkUsersToEmployeesSeeder::class);

        $user->refresh();
        $this->assertNotSame($old->id, (int) $user->entity_id);
        $this->assertSame('relinked@partner.test', Employee::findOrFail($user->entity_id)->email);
    }

    #[Test]
    public function users_that_are_already_employees_partners_or_borrowers_are_left_alone(): void
    {
        $employee = Employee::create(['partner_id' => $this->partner->id, 'first_name' => 'Already Linked']);
        $employeeUser = $this->user('emp@partner.test', ['entity_type' => Employee::class, 'entity_id' => $employee->id]);
        $partnerUser = $this->user('owner-login@partner.test', ['entity_type' => Partner::class, 'entity_id' => $this->partner->id]);
        $borrowerUser = $this->user('client@partner.test', ['entity_type' => Borrower::class, 'entity_id' => 999, 'user_type' => User::BORROWER]);

        $this->seed(LinkUsersToEmployeesSeeder::class);

        $this->assertSame(1, Employee::count());
        $this->assertSame($employee->id, (int) $employeeUser->refresh()->entity_id);
        $this->assertSame(Partner::class, $partnerUser->refresh()->entity_type);
        $this->assertSame(Borrower::class, $borrowerUser->refresh()->entity_type);
    }

    #[Test]
    public function developer_accounts_and_users_without_a_partner_are_skipped(): void
    {
        $developer = $this->user('dev@partner.test');
        $developer->assignRole(Role::create(['name' => 'Developer', 'partner_id' => $this->partner->id, 'code' => 'developer']));
        $orphan = $this->user('orphan@nowhere.test', ['partner_id' => null]);

        $this->seed(LinkUsersToEmployeesSeeder::class);

        $this->assertNull($developer->refresh()->entity_type);
        $this->assertNull($orphan->refresh()->entity_type);
        $this->assertSame(0, Employee::count());
    }

    #[Test]
    public function running_it_again_changes_nothing(): void
    {
        $this->user('jane@partner.test');

        $this->seed(LinkUsersToEmployeesSeeder::class);
        $this->seed(LinkUsersToEmployeesSeeder::class);

        $this->assertSame(1, Employee::count());
    }
}
