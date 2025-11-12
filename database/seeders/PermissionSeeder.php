<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Business\Business;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Clear cached permissions before seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2) Collect unique permission names from config
        $permissionNames = collect(config('permissions'))
            ->flatten()
            ->unique()
            ->values();

        if ($permissionNames->isEmpty()) {
            $this->command->warn('No permissions found in config/permissions.php');
            return;
        }

        // 3) Fetch all businesses with owner user eager-loaded to avoid lazy loading
        $businesses = Business::with('user')->get();
        if ($businesses->isEmpty()) {
            $this->command->warn('No businesses found. Seed businesses first.');
            return;
        }

        foreach ($businesses as $business) {
            // 3a) Seed permissions for this business (guard: api)
            foreach ($permissionNames as $name) {
                Permission::updateOrCreate([
                    'name' => $name,
                    'business_id' => $business->id,
                    'guard_name' => 'api',
                ]);
            }

            // 3b) Create default roles for this business
            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'business_id' => $business->id, 'guard_name' => 'api'],
                ['name' => 'Admin', 'guard_name' => 'api', 'business_id' => $business->id]
            );

            $managerRole = Role::updateOrCreate(
                ['name' => 'Manager', 'business_id' => $business->id, 'guard_name' => 'api'],
                ['name' => 'Manager', 'guard_name' => 'api', 'business_id' => $business->id]
            );

            $staffRole = Role::updateOrCreate(
                ['name' => 'Staff', 'business_id' => $business->id, 'guard_name' => 'api'],
                ['name' => 'Staff', 'guard_name' => 'api', 'business_id' => $business->id]
            );

            // 3c) Fetch permissions for this business to assign to roles
            $allPerms = Permission::where('business_id', $business->id)
                ->where('guard_name', 'api')
                ->get();

            $staffPerms = $allPerms->filter(function (Permission $p) {
                return Str::startsWith($p->name, ['View.', 'List.']);
            });

            $managerPerms = $allPerms->filter(function (Permission $p) {
                $startsAllowed = Str::startsWith($p->name, ['View.', 'List.', 'Create.', 'Edit.', 'Export.']);
                $startsExcluded = Str::startsWith($p->name, ['Delete.', 'Manage.']);
                return $startsAllowed && !$startsExcluded;
            });

            // 3d) Assign permissions to roles
            $adminRole->syncPermissions($allPerms);
            $managerRole->syncPermissions($managerPerms);
            $staffRole->syncPermissions($staffPerms);

            // 3e) Assign roles to users and also copy role permissions to model-based permissions
            // Owner (business->user) gets Admin
            $owner = $business->user;
            if ($owner) {
                $owner->syncRoles([$adminRole->name]);
                $owner->syncPermissions($allPerms);
            }

            // First employee gets Manager, others get Staff
            $employees = Employee::where('business_id', $business->id)->with('user')->get();
            if ($employees->isNotEmpty()) {
                $firstAssigned = false;
                foreach ($employees as $employee) {
                    $user = $employee->user;
                    if (!$user) {
                        continue;
                    }

                    if (!$firstAssigned) {
                        $user->syncRoles([$managerRole->name]);
                        $user->syncPermissions($managerPerms);
                        $firstAssigned = true;
                    } else {
                        $user->syncRoles([$staffRole->name]);
                        $user->syncPermissions($staffPerms);
                    }
                }
            }

            $this->command->info("Roles & permissions seeded for Business ID: {$business->id}");
        }

        // 4) Clear cached permissions after seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command->info('All roles, permissions, and user assignments seeded successfully for all businesses.');
    }
}
