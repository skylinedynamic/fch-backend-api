<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
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
        $partners = Partner::with('partnerUser')->get();
        if ($partners->isEmpty()) {
            $this->command->warn('No partners found. Seed partners first.');
            return;
        }

        foreach ($permissionNames as $name) {
            $module = '';
            if (Str::contains($name, ' ')) {
                $module = (string) Str::of($name)->after(' ')->trim();
            }

            Permission::updateOrCreate(
                [
                    'name' => $name,
                    'guard_name' => 'api',
                ],
                [
                    'module' => $module,
                ]
            );
        }

        // Resolve full permission set once
        $allPerms = Permission::where('guard_name', 'api')->get();

 
        foreach ($partners as $partner) {
            // 3b) Create default roles for this business
            $adminRole = Role::updateOrCreate(
                ['name' => 'Admin', 'partner_id' => $partner->id, 'guard_name' => 'api'],
                ['name' => 'Admin', 'guard_name' => 'api', 'partner_id' => $partner->id]
            );

            $managerRole = Role::updateOrCreate(
                ['name' => 'Manager', 'partner_id' => $partner->id, 'guard_name' => 'api'],
                ['name' => 'Manager', 'guard_name' => 'api', 'partner_id' => $partner->id]
            );

            $staffRole = Role::updateOrCreate(
                ['name' => 'Staff', 'partner_id' => $partner->id, 'guard_name' => 'api'],
                ['name' => 'Staff', 'guard_name' => 'api', 'partner_id' => $partner->id]
            );

            $staffPerms = $allPerms->filter(function (Permission $p) {
                return Str::startsWith($p->name, ['view ']);
            });

            $managerPerms = $allPerms->filter(function (Permission $p) {
                $startsAllowed = Str::startsWith($p->name, ['view ', 'create ', 'update ']);
                $startsExcluded = Str::startsWith($p->name, ['delete ']);
                return $startsAllowed && !$startsExcluded;
            });

            // 3d) Assign permissions to roles
            $adminRole->syncPermissions($allPerms);
            $managerRole->syncPermissions($managerPerms);
            $staffRole->syncPermissions($staffPerms);

            // 3e) Assign roles to users and also copy role permissions to model-based permissions
            // Owner (partner->partnerUser) gets Admin
            $owner = $partner->partnerUser;
            if ($owner) {
                $owner->syncRoles([$adminRole->name]);
                $owner->syncPermissions($allPerms);
            }

            // First employee gets Manager, others get Staff
            $employees = Employee::where('partner_id', $partner->id)->with('user')->get();
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

            $this->command->info("Roles & permissions seeded for Partner ID: {$partner->id}");
        }

        // 4) Clear cached permissions after seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command->info('All roles, permissions, and user assignments seeded successfully for all businesses.');
    }
}
