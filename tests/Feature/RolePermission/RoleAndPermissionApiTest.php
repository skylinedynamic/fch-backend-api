<?php

namespace Tests\Feature\RolePermission;

use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\PermissionController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Roles & Permissions: /common/roles*, /common/permissions and
 * /common/users/{user}/roles|permissions. Each partner keeps its own roles.
 * Which permissions a partner may hand out depends on its type: the host any,
 * a white-label partner the non-host ones, a certified partner only those
 * marked for certified partners - the same set the permission picker offers.
 */
#[CoversClass(RoleController::class)]
#[CoversClass(PermissionController::class)]
#[CoversClass(UserRoleController::class)]
class RoleAndPermissionApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $partnerAdmin;
    private Permission $viewLeads;
    private Permission $hostOnly;
    private Permission $notForCertified;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);

        $this->viewLeads = Permission::create(['name' => 'view leads', 'guard_name' => 'api', 'module' => 'leads', 'is_host' => false, 'is_certified' => true]);
        $this->hostOnly = Permission::create(['name' => 'view partners', 'guard_name' => 'api', 'module' => 'partners', 'is_host' => true, 'is_certified' => false]);
        $this->notForCertified = Permission::create(['name' => 'view cms', 'guard_name' => 'api', 'module' => 'cms', 'is_host' => false, 'is_certified' => false]);
    }

    private function roleFor(Partner $partner, string $name = 'Sales', array $permissions = []): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => strtolower($name)]);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function permissionNames(): array
    {
        return collect($this->postJson('/api/v1/common/permissions')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    }

    #[Test]
    public function the_role_routes_need_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/common/roles')->assertUnauthorized();
        $this->postJson('/api/v1/common/roles')->assertUnauthorized();
        $this->postJson('/api/v1/common/permissions')->assertUnauthorized();
    }

    #[Test]
    public function the_permission_picker_offers_each_partner_type_its_own_set(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));
        $this->assertSame(['view cms', 'view leads', 'view partners'], $this->permissionNames());

        $this->actingAsUser($this->partnerAdmin);
        $this->assertSame(['view cms', 'view leads'], $this->permissionNames());

        $this->actingAsUser($this->makePartnerAdmin($this->makeCertifiedPartner()));
        $this->assertSame(['view leads'], $this->permissionNames());
    }

    #[Test]
    public function a_partner_lists_only_its_own_roles(): void
    {
        $mine = $this->roleFor($this->partner, 'Mine');
        $this->roleFor($this->host, 'Hosts');
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson('/api/v1/common/roles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->postJson('/api/v1/common/roles/fetch-roles')->assertOk()->assertJsonPath('data.0.id', $mine->id);
    }

    #[Test]
    public function the_developer_role_is_hidden_from_everyone_but_developers(): void
    {
        $this->roleFor($this->partner, 'Developer')->update(['code' => 'developer']);
        $this->roleFor($this->partner, 'Sales');
        $this->actingAsUser($this->partnerAdmin);

        $names = collect($this->getJson('/api/v1/common/roles')->json('data'))->pluck('name');

        $this->assertSame(['Sales'], $names->all());
    }

    #[Test]
    public function a_partner_creates_a_role_with_permissions_it_may_grant(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Closers',
            'permission_ids' => [$this->viewLeads->id],
        ])->assertCreated()->json('data.id');

        $role = Role::findOrFail($id);
        $this->assertSame($this->partner->id, $role->partner_id);
        $this->assertTrue($role->hasPermissionTo('view leads'));
    }

    #[Test]
    public function a_role_name_is_unique_within_a_partner_only(): void
    {
        $this->roleFor($this->partner, 'Closers');
        $this->roleFor($this->host, 'Underwriters');
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/roles', ['name' => 'Closers', 'permission_ids' => [$this->viewLeads->id]])
            ->assertStatus(422);
        $this->postJson('/api/v1/common/roles', ['name' => 'Underwriters', 'permission_ids' => [$this->viewLeads->id]])
            ->assertCreated();
    }

    /**
     * Defect: create and update took any permission id, so a partner could
     * grant its own roles host-only permissions the picker never offered it.
     */
    #[Test]
    public function a_partner_cannot_grant_permissions_beyond_its_type(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Escalated',
            'permission_ids' => [$this->viewLeads->id, $this->hostOnly->id],
        ])->assertCreated()->json('data.id');

        $role = Role::findOrFail($id);
        $this->assertTrue($role->hasPermissionTo('view leads'));
        $this->assertFalse($role->hasPermissionTo('view partners'));

        $this->putJson("/api/v1/common/roles/{$id}", [
            'name' => 'Escalated',
            'permission_ids' => [$this->hostOnly->id],
        ])->assertOk();
        $this->assertFalse($role->fresh()->hasPermissionTo('view partners'));
    }

    #[Test]
    public function a_certified_partner_cannot_grant_white_label_only_permissions(): void
    {
        $certified = $this->makeCertifiedPartner();
        $this->actingAsUser($this->makePartnerAdmin($certified));

        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Team',
            'permission_ids' => [$this->viewLeads->id, $this->notForCertified->id],
        ])->assertCreated()->json('data.id');

        $this->assertFalse(Role::findOrFail($id)->hasPermissionTo('view cms'));
    }

    #[Test]
    public function the_host_may_grant_host_only_permissions(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Partner Desk',
            'permission_ids' => [$this->hostOnly->id],
        ])->assertCreated()->json('data.id');

        $this->assertTrue(Role::findOrFail($id)->hasPermissionTo('view partners'));
    }

    #[Test]
    public function updating_a_role_passes_its_new_permissions_to_everyone_holding_it(): void
    {
        $role = $this->roleFor($this->partner, 'Sales', [$this->viewLeads]);
        $staff = $this->makeStaffUser($this->partner);
        $staff->assignRole($role);
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/roles/{$role->id}", [
            'name' => 'Sales',
            'permission_ids' => [$this->notForCertified->id],
        ])->assertOk();

        $staff = $staff->fresh();
        $this->assertTrue($staff->hasPermissionTo('view cms'));
        $this->assertFalse($staff->hasPermissionTo('view leads'));
    }

    /**
     * Defect: show and update bound the role straight from the URL, so any
     * signed-in user could read another partner's role (with its members) and
     * rewrite its permissions - pushing them onto every user holding it.
     */
    #[Test]
    public function another_partners_role_cannot_be_read_or_changed(): void
    {
        $hostRole = $this->roleFor($this->host, 'Admin', [$this->hostOnly]);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/roles/{$hostRole->id}")->assertNotFound();
        $this->putJson("/api/v1/common/roles/{$hostRole->id}", [
            'name' => 'Hijacked',
            'permission_ids' => [$this->viewLeads->id],
        ])->assertNotFound();

        $hostRole->refresh();
        $this->assertSame('Admin', $hostRole->name);
        $this->assertTrue($hostRole->hasPermissionTo('view partners'));
    }

    /** A seeded, static Admin role holding two permissions, held by the partner's admin. */
    private function staticAdminRole(): Role
    {
        $admin = $this->roleFor($this->partner, 'Admin', [$this->viewLeads, $this->notForCertified]);
        $admin->update(['is_static' => true, 'code' => 'admin']);
        $this->partnerAdmin->assignRole($admin);

        return $admin;
    }

    /**
     * Defect: R2 BUG-011 - the seeded Admin role was fully editable: its
     * permissions could be unticked and saved, so the only admin could take
     * away their own access. A static role keeps the permissions it has.
     */
    #[Test]
    public function a_static_roles_permissions_cannot_be_taken_away(): void
    {
        $admin = $this->staticAdminRole();
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/roles/{$admin->id}", [
            'name' => 'Admin',
            'permission_ids' => [$this->viewLeads->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['permission_ids' => __('role_permission.role_static_cannot_change')]);

        $this->assertTrue($admin->fresh()->hasPermissionTo('view cms'));
        $this->assertTrue($this->partnerAdmin->fresh()->hasPermissionTo('view cms'));
    }

    /** R2 BUG-011 - nor can it be renamed. */
    #[Test]
    public function a_static_role_cannot_be_renamed(): void
    {
        $admin = $this->staticAdminRole();
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/roles/{$admin->id}", [
            'name' => 'Not Admin',
            'permission_ids' => [$this->viewLeads->id, $this->notForCertified->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['name' => __('role_permission.role_static_cannot_change')]);

        $this->assertSame('Admin', $admin->fresh()->name);
    }

    /** R2 BUG-011 - its description may still be edited, and an unchanged save goes through. */
    #[Test]
    public function a_static_roles_description_can_still_be_edited(): void
    {
        $admin = $this->staticAdminRole();
        $this->actingAsUser($this->partnerAdmin);

        $this->putJson("/api/v1/common/roles/{$admin->id}", [
            'name' => 'Admin',
            'description' => 'Everything, always',
            'permission_ids' => [$this->notForCertified->id, $this->viewLeads->id],
        ])->assertOk();

        $this->assertSame('Everything, always', $admin->fresh()->description);
        $this->assertTrue($admin->fresh()->hasPermissionTo('view leads'));
    }

    /** R2 BUG-011 - the role says it is a system role, so the page can show it read-only. */
    #[Test]
    public function a_role_says_whether_it_is_a_system_role(): void
    {
        $admin = $this->staticAdminRole();
        $sales = $this->roleFor($this->partner, 'Sales');
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/roles/{$admin->id}")->assertOk()->assertJsonPath('data.is_static', true);
        $this->getJson("/api/v1/common/roles/{$sales->id}")->assertOk()->assertJsonPath('data.is_static', false);
    }

    #[Test]
    public function a_role_shows_its_permissions_and_members(): void
    {
        $role = $this->roleFor($this->partner, 'Sales', [$this->viewLeads]);
        $this->makeStaffUser($this->partner)->assignRole($role);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Sales')
            ->assertJsonPath("data.permission.{$this->viewLeads->id}", 'view leads')
            ->assertJsonCount(1, 'data.users');
    }

    #[Test]
    public function an_unused_role_is_deleted_but_one_with_members_or_a_static_one_is_kept(): void
    {
        $unused = $this->roleFor($this->partner, 'Unused');
        $used = $this->roleFor($this->partner, 'Used');
        $this->makeStaffUser($this->partner)->assignRole($used);
        $static = $this->roleFor($this->partner, 'Admin');
        $static->update(['is_static' => true]);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/roles/destroy', ['ids' => [$used->id]])->assertStatus(400);
        $this->postJson('/api/v1/common/roles/destroy', ['ids' => [$static->id]])->assertStatus(400);
        $this->postJson('/api/v1/common/roles/destroy', ['ids' => [$unused->id]])->assertOk();

        $this->assertNull(Role::find($unused->id));
        $this->assertNotNull(Role::find($used->id));
        $this->assertNotNull(Role::find($static->id));
    }

    #[Test]
    public function another_partners_role_cannot_be_deleted(): void
    {
        $hostRole = $this->roleFor($this->host, 'Spare');
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/roles/destroy', ['ids' => [$hostRole->id]])->assertNotFound();

        $this->assertNotNull(Role::find($hostRole->id));
    }

    #[Test]
    public function a_partner_assigns_its_own_roles_to_its_own_staff(): void
    {
        $role = $this->roleFor($this->partner, 'Sales');
        $foreignRole = $this->roleFor($this->host, 'Hosts');
        $staff = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/users/{$staff->id}/roles", ['role_ids' => [$role->id, $foreignRole->id]])->assertOk();

        $this->assertSame(['Sales'], $staff->fresh()->getRoleNames()->all());
    }

    /**
     * Defect: the target user came straight from the URL, so a partner could
     * strip or replace the roles and permissions of any user on the platform -
     * the host's admins included - and grant any permission at all.
     */
    #[Test]
    public function another_partners_user_cannot_have_their_roles_or_permissions_changed(): void
    {
        $hostAdmin = $this->makePartnerAdmin($this->host, ['view partners']);
        $ownRole = $this->roleFor($this->partner, 'Sales');
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/users/{$hostAdmin->id}/roles", ['role_ids' => [$ownRole->id]])->assertNotFound();
        $this->assertFalse($hostAdmin->fresh()->hasRole('Sales'));
        $this->postJson("/api/v1/common/users/{$hostAdmin->id}/permissions", ['permission_ids' => [$this->viewLeads->id]])
            ->assertNotFound();

        $this->assertTrue($hostAdmin->fresh()->hasPermissionTo('view partners'));
    }

    #[Test]
    public function direct_permissions_are_limited_to_what_the_partner_may_grant(): void
    {
        $staff = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/users/{$staff->id}/permissions", [
            'permission_ids' => [$this->viewLeads->id, $this->hostOnly->id],
        ])->assertOk();

        $staff = $staff->fresh();
        $this->assertTrue($staff->hasPermissionTo('view leads'));
        $this->assertFalse($staff->hasPermissionTo('view partners'));
    }

    #[Test]
    public function role_and_permission_assignment_validate_their_lists(): void
    {
        $staff = $this->makeStaffUser($this->partner);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/users/{$staff->id}/roles", [])->assertStatus(422);
        $this->postJson("/api/v1/common/users/{$staff->id}/permissions", [])->assertStatus(422);
        $this->postJson('/api/v1/common/roles', ['name' => 'X'])->assertStatus(422);
    }

    /**
     * BUG-038: the admin form now sends an empty name instead of stopping
     * silently, and shows errors.name under the field - pin that message.
     */
    #[Test]
    public function an_empty_role_name_is_refused_with_role_name_is_required(): void
    {
        $role = $this->roleFor($this->partner, 'Closers');
        $this->actingAsUser($this->partnerAdmin);
        $payload = ['name' => '', 'permission_ids' => [$this->viewLeads->id]];

        $this->postJson('/api/v1/common/roles', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.name', 'The role name is required.');
        $this->putJson("/api/v1/common/roles/{$role->id}", $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.name', 'The role name is required.');
        $this->assertSame('Closers', $role->fresh()->name);
    }
}
