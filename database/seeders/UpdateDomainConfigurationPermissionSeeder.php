<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class UpdateDomainConfigurationPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create or update the permission in permissions table
        $targetPermission = Permission::updateOrCreate(
            ['name' => 'update domain configuration settings', 'guard_name' => 'api'],
            [
                'module' => 'settings',
                'is_host' => 0,
                'is_certified' => 0,
            ]
        );

        $this->command?->info('Domain configuration settings permission updated/created in permissions table.');

        // 2. Assign permission to roles
        $roles = Role::with('permissions')->get();

        foreach ($roles as $role) {
            $shouldAssign = false;

            if ($role->id == 1 || $role->partner_id == 1) {
                // Developer or Super Admin Partner
                $shouldAssign = true;
            } else {
                $partner = Partner::find($role->partner_id);
                if ($partner && ($role->code === 'admin' || $role->name === 'Admin')) {
                    if ($partner->partner_type == Partner::PARTNER_TYPE_WHITE_LABEL) {
                        $shouldAssign = true;
                    }
                }
            }

            if ($shouldAssign) {
                // Give permission to role
                $role->givePermissionTo($targetPermission);
                $this->command?->info("Assigned 'update domain configuration settings' permission to Role: {$role->name} (Partner ID: " . ($role->partner_id ?? 'N/A') . ")");

                // Also sync to existing users who belong to this role
                $users = User::where('partner_id', $role->partner_id)
                    ->whereHas('roles', function ($query) use ($role) {
                        $query->where('id', $role->id);
                    })
                    // givePermissionTo() reads the user's permissions; loaded
                    // here, since lazy loading throws outside production.
                    ->with('permissions')
                    ->get();

                foreach ($users as $user) {
                    if (method_exists($user, 'givePermissionTo')) {
                        $user->givePermissionTo($targetPermission);
                        $this->command?->info("Assigned permission directly to User: {$user->email}");
                    }
                }
            }
        }

        // Force all users to refresh their state
        DB::table('users')->update(['is_refresh' => 1]);

        // Finalize by clearing cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('UpdateDomainConfigurationPermissionSeeder completed successfully!');
    }
}
