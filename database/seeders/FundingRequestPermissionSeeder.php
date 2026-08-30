<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class FundingRequestPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = config('permissions');
        $modulesToSync = ['intake and review'];
        $newPermissions = collect();

        // 1. Update or Create permissions in permissions table
        foreach ($modulesToSync as $module) {
            $moduleConfig = $allPermissions[$module] ?? null;

            if (!$moduleConfig || !isset($moduleConfig['permissions'])) {
                $this->command?->error(ucfirst($module) . ' permissions not found in config/permissions.php.');
                continue;
            }

            $permissionsData = $moduleConfig['permissions'];
            $permissionNames = array_column($permissionsData, 'name');

            // Update/Create the permissions
            foreach ($permissionsData as $p) {
                $createdPerm = Permission::updateOrCreate(
                    ['name' => $p['name'], 'guard_name' => 'api'],
                    [
                        'module' => $module, 
                        'is_host' => $p['is_host'] ?? 1, 
                        'is_certified' => $p['is_certified'] ?? 0
                    ]
                );
                $newPermissions->push($createdPerm);
            }

            // Remove any permissions for this module that are NOT in the current config
            Permission::where('module', $module)
                ->whereNotIn('name', $permissionNames)
                ->delete();

            $this->command?->info(ucfirst($module) . ' permissions updated/created in permissions table.');
        }

        if ($newPermissions->isEmpty()) {
            return;
        }

        // 2. Identify all Partners (Host and Regular)
        $partners = Partner::all();
        
        if ($partners->isEmpty()) {
            $this->command?->warn('No partners found.');
        }

        foreach ($partners as $partner) {
            // Find Admin and Developer roles for this partner
            $roles = Role::where('partner_id', $partner->id)
                ->whereIn('code', ['admin', 'developer'])
                ->get();

            foreach ($roles as $role) {
                // Get current permissions NOT in these modules
                $currentOtherPermissions = $role->permissions()
                    ->whereNotIn('module', $modulesToSync)
                    ->get();
                
                // For non-host partners, filter out host-only permissions from $newPermissions
                $permissionsToAssign = clone $newPermissions;
                if (!$partner->is_host) {
                    $permissionsToAssign = $permissionsToAssign->filter(function ($perm) {
                        return $perm->is_host == 0;
                    });
                }
                
                // Sync: Other permissions + New permissions
                $role->syncPermissions($currentOtherPermissions->merge($permissionsToAssign));
                
                $this->command?->info("Synced Funding Request permissions for Role: {$role->name} (Partner ID: {$partner->id}, is_host: {$partner->is_host})");

                // Clear user permission cache
                $users = User::whereHas('roles', function($q) use ($role) {
                    $q->where('roles.id', $role->id);
                })->get();

                foreach ($users as $user) {
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }
            }
        }

        $this->command?->info('Funding Request Permission Seeding Completed accurately for all existing partners and users!');
    }
}
