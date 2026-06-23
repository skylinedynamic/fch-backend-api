<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class LenderFundingPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = config('permissions');
        $modulesToSync = ['lenders', 'funding'];
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

        // 2. Identify all Host Partners
        $hostPartners = Partner::where('is_host', 1)->get();
        
        if ($hostPartners->isEmpty()) {
            $this->command?->warn('No host partners found.');
        }

        foreach ($hostPartners as $partner) {
            // Find Admin and Developer roles for this host partner
            $roles = Role::where('partner_id', $partner->id)
                ->whereIn('code', ['admin', 'developer'])
                ->get();

            foreach ($roles as $role) {
                // Get current permissions NOT in these modules
                $currentOtherPermissions = $role->permissions()
                    ->whereNotIn('module', $modulesToSync)
                    ->get();
                
                // Sync: Other permissions + New Lender/Funding permissions
                $role->syncPermissions($currentOtherPermissions->merge($newPermissions));
                
                $this->command?->info("Synced Lender/Funding permissions for Role: {$role->name} (Partner ID: {$partner->id})");

                // Clear user permission cache
                $users = User::whereHas('roles', function($q) use ($role) {
                    $q->where('roles.id', $role->id);
                })->get();

                foreach ($users as $user) {
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }
            }
        }

        $this->command?->info('Lender and Funding Permission Seeding Completed accurately for all host partners and users!');
    }
}
