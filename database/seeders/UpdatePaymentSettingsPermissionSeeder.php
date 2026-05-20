<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class UpdatePaymentSettingsPermissionSeeder extends Seeder
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
        
        // The specific permission we are granting
        $targetPermissionName = 'update payment settings';

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

        $this->command?->info('Payment settings permission updated in permissions table.');

        // 2. Get the specific permission we want to assign
        $targetPermission = Permission::where('name', $targetPermissionName)
            ->where('guard_name', 'api')
            ->first();

        if (!$targetPermission) {
            $this->command?->error('Target permission not found in database.');
            return;
        }

        // 3. Assign permission to roles
        $roles = Role::with('permissions')->get();

        foreach ($roles as $role) {
            $shouldAssign = false;

            if ($role->id == 1 || $role->partner_id == 1) {
                // Developer or Super Admin Partner always gets it
                $shouldAssign = true;
            } else {
                $partner = Partner::find($role->partner_id);
                if ($partner && ($role->code === 'admin' || $role->name === 'Admin')) {
                    // Give only to White-Label Partners (since is_host is now 0)
                    if ($partner->partner_type == 'White-Label Partner (WL)') {
                        $shouldAssign = true;
                    }
                }
            }

            if ($shouldAssign) {
                // Give permission to role
                if (!$role->hasPermissionTo($targetPermissionName)) {
                    $role->givePermissionTo($targetPermissionName);
                }
                
                $this->command?->info("Assigned payment settings permission to Role: {$role->name} (Partner ID: " . ($role->partner_id ?? 'N/A') . ")");

                // Also sync to existing users who might have direct permissions in model_has_permissions
                $users = User::role($role->name)->with('permissions')->get();
                foreach ($users as $user) {
                    if (method_exists($user, 'givePermissionTo') && !$user->hasPermissionTo($targetPermissionName)) {
                        $user->givePermissionTo($targetPermissionName);
                    }
                }
            }
        }

        // Force all users to refresh their state (kick them to reload permissions)
        DB::table('users')->update(['is_refresh' => 1]);

        // Finalize by clearing cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('UpdatePaymentSettingsPermissionSeeder completed accurately!');
    }
}
