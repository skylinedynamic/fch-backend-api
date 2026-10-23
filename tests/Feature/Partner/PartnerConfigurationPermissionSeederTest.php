<?php

namespace Tests\Feature\Partner;

use App\Models\Common\Role;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Database\Seeders\PartnerConfigurationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * `manage partner configuration` goes to roles 1 and 2 (the host's Developer
 * and Admin) and to the users holding them - and to no other role.
 */
class PartnerConfigurationPermissionSeederTest extends TestCase
{
    private const PERMISSION = 'manage partner configuration';

    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Role $developer;

    private Role $admin;

    private Role $other;

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner();
        $this->developer = Role::create(['id' => 1, 'name' => 'Developer', 'partner_id' => $host->id]);
        $this->admin = Role::create(['id' => 2, 'name' => 'Admin', 'partner_id' => $host->id]);
        $this->other = Role::create(['id' => 3, 'name' => 'Sales', 'partner_id' => $host->id]);
    }

    private function userWith(Role $role): User
    {
        $user = $this->makePartnerAdmin(Partner::findOrFail($role->partner_id));
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function roles_one_and_two_and_their_users_get_the_permission(): void
    {
        $developerUser = $this->userWith($this->developer);
        $adminUser = $this->userWith($this->admin);
        $salesUser = $this->userWith($this->other);

        $this->seed(PartnerConfigurationPermissionSeeder::class);

        $this->assertTrue($this->developer->fresh()->hasPermissionTo(self::PERMISSION));
        $this->assertTrue($this->admin->fresh()->hasPermissionTo(self::PERMISSION));
        $this->assertFalse($this->other->fresh()->hasPermissionTo(self::PERMISSION));

        $direct = fn (User $user) => DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_id', $user->id)
            ->where('permissions.name', self::PERMISSION)
            ->exists();

        $this->assertTrue($direct($developerUser));
        $this->assertTrue($direct($adminUser));
        $this->assertFalse($direct($salesUser));
        $this->assertSame(1, (int) $adminUser->fresh()->is_refresh);
    }

    #[Test]
    public function the_permission_sits_in_the_partners_module(): void
    {
        $this->seed(PartnerConfigurationPermissionSeeder::class);

        $this->assertSame('partners', DB::table('permissions')->where('name', self::PERMISSION)->value('module'));
        $this->assertNotNull(collect(config('permissions.partners.permissions'))->firstWhere('name', self::PERMISSION));
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $this->seed(PartnerConfigurationPermissionSeeder::class);
        $this->seed(PartnerConfigurationPermissionSeeder::class);

        $this->assertSame(1, DB::table('permissions')->where('name', self::PERMISSION)->count());
        $this->assertSame(1, $this->admin->fresh()->permissions()->where('name', self::PERMISSION)->count());
    }
}
