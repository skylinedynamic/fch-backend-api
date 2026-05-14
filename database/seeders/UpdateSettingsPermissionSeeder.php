<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class UpdateSettingsPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Update or Create Settings permissions in permissions table
        $allPermissions = config('permissions');
        $settingsConfig = $allPermissions['settings'] ?? null;
        
        if (!$settingsConfig || !isset($settingsConfig['permissions'])) {
            $this->command?->error('Settings permissions not found in config/permissions.php.');
            return;
        }

        $settingsPermissionsData = $settingsConfig['permissions'];
        
        // Update/Create the permissions
        $newPermissionNames = [
            'update email configuration settings',
            'update branding settings'
        ];

        foreach ($settingsPermissionsData as $p) {
            Permission::updateOrCreate(
                ['name' => $p['name'], 'guard_name' => 'api'],
                [
                    'module' => 'settings', 
                    'is_host' => $p['is_host'] ?? 1, 
                    'is_certified' => $p['is_certified'] ?? 0
                ]
            );
        }

        $this->command?->info('Settings permissions updated/created in permissions table.');

        // 2. Get the specific permissions we want to assign
        $targetPermissions = Permission::whereIn('name', $newPermissionNames)
            ->where('guard_name', 'api')
            ->get();

        if ($targetPermissions->isEmpty()) {
            $this->command?->error('Target permissions not found in database.');
            return;
        }

        // 3. Assign permissions to roles
        $roles = Role::with('permissions')->get();

        foreach ($roles as $role) {
            $shouldAssign = false;

            if ($role->id == 1 || $role->partner_id == 1) {
                // Developer or Super Admin Partner
                $shouldAssign = true;
            } else {
                $partner = Partner::find($role->partner_id);
                if ($partner && ($role->code === 'admin' || $role->name === 'Admin')) {
                    if ($partner->partner_type == 'White-Label Partner (WL)') {
                        // WL partners get all is_host = 0 permissions
                        $shouldAssign = true;
                    } elseif ($partner->partner_type == 'Certified Partner') {
                        // Certified partners get is_host = 0 && is_certified = 1
                        $shouldAssign = true;
                    }
                }
            }

            if ($shouldAssign) {
                // Give permissions to role
                $role->givePermissionTo($targetPermissions);
                $this->command?->info("Assigned new settings permissions to Role: {$role->name} (Partner ID: " . ($role->partner_id ?? 'N/A') . ")");

                // Also sync to existing users who might have direct permissions
                $users = User::role($role->name)->with('permissions')->get();
                foreach ($users as $user) {
                    // Spatie's syncPermissions or givePermissionTo handles model_has_permissions
                    // But usually, we rely on roles. Some parts of the system might be syncing to models directly.
                    // To be safe and follow project pattern in UpdateAdminPermissionsSeeder:
                    if (method_exists($user, 'givePermissionTo')) {
                        $user->givePermissionTo($targetPermissions);
                    }
                }
            }
        }

        // Force all users to refresh their state
        DB::table('users')->update(['is_refresh' => 1]);

        // Finalize by clearing cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('UpdateSettingsPermissionSeeder completed accurately!');
    }
}
