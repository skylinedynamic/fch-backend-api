<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class CmsPermissionUpdateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Update or Create CMS permissions in permissions table
        $allPermissions = config('permissions');
        $cmsConfig = $allPermissions['cms'] ?? null;
        
        if (!$cmsConfig || !isset($cmsConfig['permissions'])) {
            $this->command?->error('CMS permissions not found in config/permissions.php.');
            return;
        }

        $cmsPermissionsData = $cmsConfig['permissions'];
        $cmsPermissionNames = array_column($cmsPermissionsData, 'name');

        // Update/Create the permissions
        foreach ($cmsPermissionsData as $p) {
            Permission::updateOrCreate(
                ['name' => $p['name'], 'guard_name' => 'api'],
                [
                    'module' => 'cms', 
                    'is_host' => $p['is_host'] ?? 1, 
                    'is_certified' => $p['is_certified'] ?? 0
                ]
            );
        }

        // Remove any CMS permissions that are NOT in the current config (Strict Sync)
        Permission::where('module', 'cms')
            ->whereNotIn('name', $cmsPermissionNames)
            ->delete();

        $this->command?->info('CMS permissions updated/created in permissions table.');

        // 2. Identify all Host Partners
        $hostPartners = Partner::where('is_host', 1)->get();
        
        if ($hostPartners->isEmpty()) {
            $this->command?->warn('No host partners found.');
        }

        $cmsPermissions = Permission::where('module', 'cms')->where('guard_name', 'api')->get();

        foreach ($hostPartners as $partner) {
            // Find Admin and Developer roles for this host partner
            $roles = Role::where('partner_id', $partner->id)
                ->whereIn('code', ['admin', 'developer'])
                ->get();

            foreach ($roles as $role) {
                // Get current non-CMS permissions
                $currentNonCmsPermissions = $role->permissions()->where('module', '!=', 'cms')->get();
                
                // Sync: Non-CMS + New CMS permissions
                $role->syncPermissions($currentNonCmsPermissions->merge($cmsPermissions));
                
                $this->command?->info("Synced CMS permissions for Role: {$role->name} (Partner ID: {$partner->id})");

                // 3. Ensure all users with this role have the updated permissions
                $users = User::whereHas('roles', function($q) use ($role) {
                    $q->where('roles.id', $role->id);
                })->get();

                foreach ($users as $user) {
                    // Spatie handles inheritance via roles, but if we sync role permissions, 
                    // we should clear user permission cache
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                }
            }
        }

        $this->command?->info('CMS Permission Seeding Completed accurately for all host partners and users!');
    }
}
