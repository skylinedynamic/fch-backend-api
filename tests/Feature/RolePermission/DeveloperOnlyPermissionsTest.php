<?php

namespace Tests\Feature\RolePermission;

use App\Enums\Permission as PermissionName;
use App\Enums\PermissionModule;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\PermissionController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Database\Seeders\BusinessInsightsPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Some permissions belong to the Developer role alone - the Business Insights
 * group. Only a Developer sees them in the permission picker, and no other
 * role or user can be given them, not even by a Developer.
 */
#[CoversClass(PermissionController::class)]
#[CoversClass(RoleController::class)]
#[CoversClass(UserRoleController::class)]
class DeveloperOnlyPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostAdmin;
    private User $developer;
    private Permission $viewLeads;
    private Permission $insights;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->developer = $this->makeDeveloper($this->host);

        $this->viewLeads = Permission::create(['name' => 'view leads', 'guard_name' => 'api', 'module' => 'leads', 'is_host' => false, 'is_certified' => true]);
        $this->insights = Permission::create([
            'name' => PermissionName::ViewBusinessInsights->value,
            'guard_name' => 'api',
            'module' => PermissionModule::BusinessInsights->value,
            'is_host' => true,
            'is_certified' => false,
            'is_developer' => true,
        ]);
    }

    private function pickerNames(): array
    {
        return collect($this->postJson('/api/v1/common/permissions')->assertOk()->json('data'))->pluck('name')->all();
    }

    #[Test]
    public function the_business_insights_permissions_belong_to_the_developer_role(): void
    {
        $insights = array_filter(PermissionName::cases(), fn (PermissionName $p) => $p->module() === PermissionModule::BusinessInsights);

        $this->assertSame(
            ['view business insights', 'export business insights', 'manage business insights report'],
            array_values(array_map(fn (PermissionName $p) => $p->value, $insights))
        );
        foreach ($insights as $permission) {
            $this->assertTrue($permission->isDeveloper(), "{$permission->value} is developer-only");
            $this->assertTrue($permission->isHost());
            $this->assertFalse($permission->isCertified());
        }
        $this->assertSame('business insights', PermissionModule::BusinessInsights->value);
    }

    #[Test]
    public function only_a_developer_sees_them_in_the_permission_picker(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->assertNotContains($this->insights->name, $this->pickerNames());

        $this->actingAsUser($this->makePartnerAdmin($this->makeWhiteLabelPartner()));
        $this->assertNotContains($this->insights->name, $this->pickerNames());

        $this->actingAsUser($this->developer);
        $this->assertContains($this->insights->name, $this->pickerNames());

        $grouped = collect($this->postJson('/api/v1/common/permissions', ['is_groupby' => true])->json('data'))->pluck('module');
        $this->assertContains('business insights', $grouped->all());
    }

    /** The Business Insights group comes first in the picker, before the older groups. */
    #[Test]
    public function the_business_insights_group_is_listed_first(): void
    {
        $export = Permission::create([
            'name' => PermissionName::ExportBusinessInsights->value,
            'guard_name' => 'api',
            'module' => PermissionModule::BusinessInsights->value,
            'is_host' => true,
            'is_certified' => false,
            'is_developer' => true,
        ]);
        $this->actingAsUser($this->developer);

        $grouped = $this->postJson('/api/v1/common/permissions', ['is_groupby' => true])->assertOk()->json('data');
        $this->assertSame('business insights', $grouped[0]['module']);
        $this->assertSame([$this->insights->id, $export->id], array_column($grouped[0]['permissions'], 'id'));
        $this->assertSame('leads', $grouped[1]['module']);

        $flat = collect($this->postJson('/api/v1/common/permissions')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame([$this->insights->name, $export->name], array_slice($flat, 0, 2));

        // Anyone else's list starts with the groups it always had.
        $this->actingAsUser($this->hostAdmin);
        $grouped = $this->postJson('/api/v1/common/permissions', ['is_groupby' => true])->assertOk()->json('data');
        $this->assertSame('leads', $grouped[0]['module']);
    }

    /** A Developer may hand them to the host's roles; no one else can. */
    #[Test]
    public function a_developer_gives_them_to_a_new_host_role_but_a_host_admin_cannot(): void
    {
        $this->actingAsUser($this->developer);
        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Analysts',
            'permission_ids' => [$this->viewLeads->id, $this->insights->id],
        ])->assertCreated()->json('data.id');

        $role = Role::findOrFail($id);
        $this->assertSame($this->host->id, $role->partner_id);
        $this->assertTrue($role->hasPermissionTo($this->insights->name));

        $this->actingAsUser($this->hostAdmin);
        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Analysts 2',
            'permission_ids' => [$this->viewLeads->id, $this->insights->id],
        ])->assertCreated()->json('data.id');

        $role = Role::findOrFail($id);
        $this->assertTrue($role->hasPermissionTo('view leads'));
        $this->assertFalse($role->hasPermissionTo($this->insights->name));
    }

    #[Test]
    public function a_developer_gives_them_to_an_existing_host_role_and_its_members(): void
    {
        $role = Role::create(['name' => 'Analysts', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'analysts']);
        $member = $this->makeStaffUser($this->host);
        $member->assignRole($role);
        $this->actingAsUser($this->developer);

        $this->putJson("/api/v1/common/roles/{$role->id}", ['name' => 'Analysts', 'permission_ids' => [$this->insights->id]])->assertOk();

        $this->assertTrue($role->fresh()->hasPermissionTo($this->insights->name));
        $this->assertTrue($member->fresh()->hasPermissionTo($this->insights->name));
    }

    /**
     * The host admin does not see them, so saving a role it edits must not
     * strip the ones a Developer gave it - nor add any.
     */
    #[Test]
    public function a_host_admin_editing_the_role_keeps_what_a_developer_gave_it(): void
    {
        $role = Role::create(['name' => 'Analysts', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'analysts']);
        $role->syncPermissions([$this->insights]);
        $member = $this->makeStaffUser($this->host);
        $member->assignRole($role);
        $member->syncPermissions([$this->insights]);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson("/api/v1/common/roles/{$role->id}", ['name' => 'Analysts', 'permission_ids' => [$this->viewLeads->id]])->assertOk();

        $role = $role->fresh();
        $this->assertTrue($role->hasPermissionTo('view leads'));
        $this->assertTrue($role->hasPermissionTo($this->insights->name));
        $this->assertTrue($member->fresh()->hasPermissionTo($this->insights->name));
    }

    #[Test]
    public function a_developer_gives_them_directly_to_host_staff(): void
    {
        $staff = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->developer);

        $this->postJson("/api/v1/common/users/{$staff->id}/permissions", ['permission_ids' => [$this->viewLeads->id, $this->insights->id]])->assertOk();

        $this->assertTrue($staff->fresh()->hasPermissionTo('view leads'));
        $this->assertTrue($staff->fresh()->hasPermissionTo($this->insights->name));
    }

    #[Test]
    public function a_host_admin_cannot_give_them_directly_and_does_not_strip_them(): void
    {
        $staff = $this->makeStaffUser($this->host);
        $staff->givePermissionTo($this->insights);
        $other = $this->makeStaffUser($this->host);
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/users/{$other->id}/permissions", ['permission_ids' => [$this->insights->id]])->assertOk();
        $this->postJson("/api/v1/common/users/{$staff->id}/permissions", ['permission_ids' => [$this->viewLeads->id]])->assertOk();

        $this->assertFalse($other->fresh()->hasPermissionTo($this->insights->name));
        $this->assertTrue($staff->fresh()->hasPermissionTo('view leads'));
        $this->assertTrue($staff->fresh()->hasPermissionTo($this->insights->name));
    }

    /** Never to another partner: its roles and users cannot hold them, whoever asks. */
    #[Test]
    public function no_other_partner_can_be_given_them(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $partnerAdmin = $this->makePartnerAdmin($partner);
        $this->actingAsUser($partnerAdmin);

        $id = $this->postJson('/api/v1/common/roles', [
            'name' => 'Analysts',
            'permission_ids' => [$this->viewLeads->id, $this->insights->id],
        ])->assertCreated()->json('data.id');
        $this->assertFalse(Role::findOrFail($id)->hasPermissionTo($this->insights->name));

        $staff = $this->makeStaffUser($partner);
        $this->postJson("/api/v1/common/users/{$staff->id}/permissions", ['permission_ids' => [$this->insights->id]])->assertOk();
        $this->assertFalse($staff->fresh()->hasPermissionTo($this->insights->name));

        // A Developer reaches only the host's own roles and users.
        $this->actingAsUser($this->developer);
        $this->postJson("/api/v1/common/users/{$staff->id}/permissions", ['permission_ids' => [$this->insights->id]])->assertNotFound();
        $this->putJson("/api/v1/common/roles/{$id}", ['name' => 'Analysts', 'permission_ids' => [$this->insights->id]])->assertNotFound();
        $this->assertFalse(Role::findOrFail($id)->hasPermissionTo($this->insights->name));
    }

    /**
     * There is one Developer role - the host's. A partner never gets one from
     * the seeders, and a role coded "developer" under a partner (left from an
     * old seed) makes no one a Developer: no developer-only permissions shown.
     */
    #[Test]
    public function the_only_developer_role_is_the_hosts(): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            [$this->host->id],
            Role::where('code', Role::CODE_DEVELOPER)->pluck('partner_id')->all()
        );
        $this->assertTrue(Role::where('partner_id', $partner->id)->where('code', 'admin')->exists());

        $stray = Role::query()->create(['name' => 'Developer', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => Role::CODE_DEVELOPER]);
        $partnerUser = $this->makeStaffUser($partner);
        $partnerUser->assignRole($stray);

        $this->assertFalse($partnerUser->fresh()->isDeveloper());
        $this->actingAsUser($partnerUser);
        $this->assertNotContains($this->insights->name, $this->pickerNames());
    }

    #[Test]
    public function the_seeders_give_them_to_the_developer_role_and_its_users_only(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(BusinessInsightsPermissionSeeder::class);

        $names = array_map(
            fn (PermissionName $p) => $p->value,
            array_filter(PermissionName::cases(), fn (PermissionName $p) => $p->isDeveloper())
        );

        foreach ($names as $name) {
            $this->assertTrue((bool) Permission::where('name', $name)->value('is_developer'), "{$name} is flagged");
        }
        $this->assertSame(0, Permission::where('is_developer', false)->whereIn('name', $names)->count());

        $developerRole = Role::where('partner_id', $this->host->id)->where('code', Role::CODE_DEVELOPER)->firstOrFail();
        $adminRole = Role::where('partner_id', $this->host->id)->where('code', 'admin')->firstOrFail();
        foreach ($names as $name) {
            $this->assertTrue($developerRole->hasPermissionTo($name));
            $this->assertFalse($adminRole->hasPermissionTo($name), "Admin must not hold {$name}");
            $this->assertTrue($this->developer->fresh()->hasDirectPermission($name));
            $this->assertFalse($this->hostAdmin->fresh()->hasPermissionTo($name));
        }
        $this->assertTrue((bool) $this->developer->fresh()->is_refresh);

        // A host role a Developer gave one to keeps it on a re-run; another
        // partner's role or user holding one (by hand) loses it.
        $view = PermissionName::ViewBusinessInsights->value;
        $hostRole = Role::create(['name' => 'Analysts', 'guard_name' => 'api', 'partner_id' => $this->host->id, 'code' => 'analysts']);
        $hostRole->givePermissionTo($view);
        $partner = $this->makeWhiteLabelPartner();
        $partnerRole = Role::create(['name' => 'Sneaky', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'sneaky']);
        $partnerRole->givePermissionTo($view);
        $partnerUser = $this->makeStaffUser($partner);
        $partnerUser->givePermissionTo($view);

        // Safe to run again.
        $this->seed(BusinessInsightsPermissionSeeder::class);
        $this->assertSame(count($names), Permission::whereIn('name', $names)->count());

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($hostRole->fresh()->hasPermissionTo($view));
        $this->assertFalse($partnerRole->fresh()->hasPermissionTo($view));
        $this->assertFalse($partnerUser->fresh()->hasPermissionTo($view));
        $this->assertTrue((bool) $partnerUser->fresh()->is_refresh);
    }
}
