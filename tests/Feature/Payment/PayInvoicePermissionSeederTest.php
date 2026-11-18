<?php

namespace Tests\Feature\Payment;

use App\Enums\Permission as PermissionName;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Database\Seeders\PayInvoicePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-202: "Pay Invoice" lets a partner user pay the partner's own invoices. It
 * is given to every static role (each partner's Admin, the host's Admin and
 * Developer) and to every user holding one, and those users are flagged to
 * reload their permissions.
 */
class PayInvoicePermissionSeederTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function staticRole(Partner $partner, string $name = 'Admin'): Role
    {
        return Role::create(['name' => $name, 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => strtolower($name), 'is_static' => true]);
    }

    private function userWithRole(Partner $partner, Role $role): User
    {
        $user = $this->makeStaffUser($partner, [], ['is_refresh' => 0]);
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function the_permission_is_defined_for_partners_in_the_invoices_module(): void
    {
        $this->seed(PayInvoicePermissionSeeder::class);

        $permission = Permission::where('name', PermissionName::PayInvoice->value)->where('guard_name', 'api')->sole();
        $this->assertSame('invoices', $permission->module);
        $this->assertSame(0, (int) $permission->is_host);
        $this->assertSame(1, (int) $permission->is_certified);

        $this->assertContains(PermissionName::PayInvoice->value, collect(config('permissions.invoices.permissions'))->pluck('name'));
    }

    #[Test]
    public function every_static_role_and_its_users_get_it_and_reload_their_permissions(): void
    {
        $this->makeHostPartner();
        $partner = $this->makeCertifiedPartner();
        $admin = $this->staticRole($partner);
        $sales = Role::create(['name' => 'Sales', 'guard_name' => 'api', 'partner_id' => $partner->id, 'code' => 'sales']);
        $owner = $this->userWithRole($partner, $admin);
        $staff = $this->userWithRole($partner, $admin);
        $salesUser = $this->userWithRole($partner, $sales);

        $this->seed(PayInvoicePermissionSeeder::class);

        $this->assertTrue($admin->fresh()->hasPermissionTo(PermissionName::PayInvoice->value));
        $this->assertFalse($sales->fresh()->hasPermissionTo(PermissionName::PayInvoice->value), 'not a static role');

        // Users carry their own copy of their role's permissions in this app.
        foreach ([$owner, $staff] as $user) {
            $this->assertTrue(DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->where('model_id', $user->id)->where('permissions.name', PermissionName::PayInvoice->value)->exists());
            $this->assertSame(1, (int) $user->fresh()->is_refresh);
        }
        $this->assertFalse($salesUser->fresh()->getDirectPermissions()->contains('name', PermissionName::PayInvoice->value));
        $this->assertSame(0, (int) $salesUser->fresh()->is_refresh);
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $partner = $this->makeCertifiedPartner();
        $role = $this->staticRole($partner);
        $this->userWithRole($partner, $role);

        $this->seed(PayInvoicePermissionSeeder::class);
        $this->seed(PayInvoicePermissionSeeder::class);

        $this->assertSame(1, Permission::where('name', PermissionName::PayInvoice->value)->count());
        $this->assertSame(1, $role->fresh()->permissions()->where('name', PermissionName::PayInvoice->value)->count());
    }
}
