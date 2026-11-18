<?php

namespace Tests\Feature\RolePermission;

use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Developer role is reserved. Nobody can create or rename a role to
 * "Developer", only a Developer can give someone the role, and a Developer's
 * account appears in no list a non-Developer can open: users, employees, a
 * role's members, the team pickers or the appointment people list.
 */
#[CoversClass(RoleController::class)]
#[CoversClass(UserRoleController::class)]
class ReservedDeveloperRoleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostAdmin;
    private User $developer;
    private Role $developerRole;
    private Role $salesRole;
    private Permission $viewLeads;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->developer = $this->makeDeveloper($this->host);
        $this->developerRole = Role::where('partner_id', $this->host->id)->where('code', Role::CODE_DEVELOPER)->firstOrFail();
        $this->salesRole = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'sales']);
        $this->viewLeads = Permission::create(['name' => 'view leads', 'guard_name' => 'api', 'module' => 'leads', 'is_host' => false, 'is_certified' => true]);
    }

    public static function reservedNames(): array
    {
        return [
            'as seeded' => ['Developer'],
            'lower case' => ['developer'],
            'upper case' => ['DEVELOPER'],
            'padded' => ['  Developer  '],
        ];
    }

    // ------------------------------------------------------- reserved name

    #[Test]
    #[DataProvider('reservedNames')]
    public function no_one_can_create_a_role_named_developer(string $name): void
    {
        // Not even another partner, which has no Developer role of its own yet.
        $partner = $this->makeWhiteLabelPartner();

        foreach ([$this->hostAdmin, $this->developer, $this->makePartnerAdmin($partner)] as $user) {
            $this->actingAsUser($user);

            $this->postJson('/api/v1/common/roles', ['name' => $name, 'permission_ids' => [$this->viewLeads->id]])
                ->assertStatus(422)
                ->assertJsonPath('errors.name', __('role_permission.name_reserved'));
        }

        $this->assertSame(1, Role::where('code', Role::CODE_DEVELOPER)->count());
    }

    #[Test]
    public function a_role_cannot_be_renamed_to_developer(): void
    {
        $this->actingAsUser($this->developer);

        $this->putJson("/api/v1/common/roles/{$this->salesRole->id}", ['name' => 'developer', 'permission_ids' => [$this->viewLeads->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.name', __('role_permission.name_reserved'));

        $this->assertSame('Sales', $this->salesRole->fresh()->name);
    }

    #[Test]
    public function a_name_that_only_contains_developer_is_still_allowed(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/roles', ['name' => 'Developer Relations', 'permission_ids' => [$this->viewLeads->id]])
            ->assertCreated();
    }

    // ------------------------------------------- changing role permissions

    /** A Developer changes any role's permissions - the static Admin too (its name stays). */
    #[Test]
    public function a_developer_changes_the_static_admin_roles_permissions(): void
    {
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'admin']);
        $admin->update(['is_static' => true]);
        $member = $this->makeStaffUser($this->host);
        $member->assignRole($admin);
        $this->actingAsUser($this->developer);

        $this->putJson("/api/v1/common/roles/{$admin->id}", ['name' => 'Renamed', 'description' => 'Runs the host', 'permission_ids' => [$this->viewLeads->id]])
            ->assertOk();

        $admin = $admin->fresh();
        $this->assertSame('Admin', $admin->name);
        $this->assertSame('Runs the host', $admin->description);
        $this->assertTrue($admin->hasPermissionTo('view leads'));
        $this->assertTrue($member->fresh()->hasPermissionTo('view leads'));

        // Anyone else still cannot.
        $this->actingAsUser($this->hostAdmin);
        $this->putJson("/api/v1/common/roles/{$admin->id}", ['name' => 'Admin', 'permission_ids' => [$this->viewLeads->id]])->assertForbidden();
    }

    /** The Developer role itself is fixed - for everyone, a Developer included. */
    #[Test]
    public function no_one_changes_the_developer_roles_permissions(): void
    {
        $this->developerRole->syncPermissions([$this->viewLeads]);

        foreach ([$this->developer, $this->hostAdmin] as $user) {
            $this->actingAsUser($user);
            $this->putJson("/api/v1/common/roles/{$this->developerRole->id}", ['name' => 'Developer2', 'permission_ids' => [$this->viewLeads->id]])
                ->assertForbidden();
        }

        $this->assertTrue($this->developerRole->fresh()->hasPermissionTo('view leads'));
        $this->assertSame('Developer', $this->developerRole->fresh()->name);
    }

    /** A Developer's own permissions are the Developer role's: no one changes them directly. */
    #[Test]
    public function no_one_changes_a_developer_users_permissions_directly(): void
    {
        $this->developer->givePermissionTo($this->viewLeads);
        $other = $this->makeDeveloper($this->host);
        $this->actingAsUser($other);

        $this->postJson("/api/v1/common/users/{$this->developer->id}/permissions", ['permission_ids' => [$this->viewLeads->id]])->assertForbidden();

        $this->assertTrue($this->developer->fresh()->hasPermissionTo('view leads'));
    }

    #[Test]
    public function each_role_says_whether_the_viewer_may_edit_it(): void
    {
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'admin']);
        $admin->update(['is_static' => true]);

        $editable = fn () => collect($this->getJson('/api/v1/common/roles')->assertOk()->json('data'))->pluck('is_editable', 'code')->all();

        $this->actingAsUser($this->developer);
        $this->assertSame(['developer' => false, 'sales' => true, 'admin' => true], $editable());
        $this->getJson("/api/v1/common/roles/{$admin->id}")->assertJsonPath('data.is_editable', true);
        $this->getJson("/api/v1/common/roles/{$this->developerRole->id}")->assertJsonPath('data.is_editable', false);

        $this->actingAsUser($this->hostAdmin);
        $this->assertSame(['sales' => true, 'admin' => false], $editable());
        $this->getJson("/api/v1/common/roles/{$admin->id}")->assertJsonPath('data.is_editable', false);
    }

    // ---------------------------------------------- giving the role away

    #[Test]
    public function only_a_developer_can_give_someone_the_developer_role(): void
    {
        $staff = $this->makeStaffUser($this->host);

        $this->actingAsUser($this->hostAdmin);
        $this->postJson("/api/v1/common/users/{$staff->id}/roles", ['role_ids' => [$this->developerRole->id, $this->salesRole->id]])->assertOk();
        $this->assertSame(['Sales'], $staff->fresh()->getRoleNames()->all());

        $this->actingAsUser($this->developer);
        $this->postJson("/api/v1/common/users/{$staff->id}/roles", ['role_ids' => [$this->developerRole->id]])->assertOk();
        $this->assertTrue($staff->fresh()->isDeveloper());
    }

    #[Test]
    public function a_new_user_cannot_be_made_a_developer_by_a_non_developer(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/users', [
            'name' => 'New Hire',
            'email' => 'hire-' . uniqid() . '@example.test',
            'password' => 'secret123',
            'role_id' => $this->developerRole->id,
            'employee_id' => $this->employee()->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['role_id']);

        $this->assertSame(1, $this->developerRole->users()->count());
    }

    #[Test]
    public function an_employee_account_cannot_be_made_a_developer_by_a_non_developer(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/employees', [
            'first_name' => 'New',
            'last_name' => 'Hire',
            'email' => 'emp-' . uniqid() . '@example.test',
            'country' => 'United States',
            'create_account' => 1,
            'password' => 'secret123',
            'role_id' => $this->developerRole->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['role_id']);

        $this->assertSame(1, $this->developerRole->users()->count());
    }

    // ------------------------------------------------- hidden from lists

    #[Test]
    public function a_developer_is_not_a_member_of_any_role_a_non_developer_reads(): void
    {
        // A developer holding a second role, so it would show under that one too.
        $this->developer->assignRole($this->salesRole);
        $this->actingAsUser($this->hostAdmin);

        $this->getJson("/api/v1/common/roles/{$this->salesRole->id}")->assertOk()->assertJsonCount(0, 'data.users');
    }

    #[Test]
    public function a_developers_employee_record_is_hidden_from_the_employee_lists(): void
    {
        $colleague = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->hostAdmin);

        $all = collect($this->getJson('/api/v1/common/employees')->assertOk()->json('data'))->pluck('id');
        $page = collect($this->postJson('/api/v1/common/employees/fetch-employees')->assertOk()->json('data'))->pluck('id');

        foreach ([$all, $page] as $ids) {
            $this->assertContains($colleague->entity_id, $ids->all());
            $this->assertNotContains($this->developer->entity_id, $ids->all());
        }
        $this->getJson("/api/v1/common/employees/{$this->developer->entity_id}")->assertNotFound();
    }

    #[Test]
    public function a_developer_is_left_out_of_the_team_pickers(): void
    {
        $colleague = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->hostAdmin);

        $team = collect($this->postJson('/api/v1/common/get-all-users')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($colleague->id, $team->all());
        $this->assertNotContains($this->developer->id, $team->all());

        $bookable = collect($this->postJson('/api/v1/common/appointments/fetch-selectable-users')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($colleague->id, $bookable->all());
        $this->assertNotContains($this->developer->id, $bookable->all());
    }

    #[Test]
    public function a_developer_still_sees_developers_everywhere(): void
    {
        $other = $this->makeDeveloper($this->host);
        $this->actingAsUser($this->developer);

        $users = collect($this->postJson('/api/v1/common/users/fetch-users')->assertOk()->json('data'))->pluck('id');
        $employees = collect($this->postJson('/api/v1/common/employees/fetch-employees')->assertOk()->json('data'))->pluck('id');
        $team = collect($this->postJson('/api/v1/common/get-all-users')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($other->id, $users->all());
        $this->assertContains($other->entity_id, $employees->all());
        $this->assertContains($other->id, $team->all());
        $this->getJson("/api/v1/common/roles/{$this->developerRole->id}")->assertOk()->assertJsonCount(2, 'data.users');
    }

    private function employee(): Employee
    {
        return Employee::create([
            'partner_id' => $this->host->id,
            'first_name' => 'New',
            'last_name' => 'Hire',
            'email' => 'hire-' . uniqid() . '@example.test',
        ]);
    }
}
