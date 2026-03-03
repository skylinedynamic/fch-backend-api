<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class UpdateAdminPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Forget cached permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Get all Admin roles and Developer role with ID 1
        $roles = Role::where('name', 'Admin')
            ->orWhere('id', 1)
            ->get();

        foreach ($roles as $role) {
            $permissions = collect();

            if ($role->id == 1 || $role->partner_id == 1) {
                // Developer role with id=1 OR Admin with partner_id = 1 gets ALL permissions
                $permissions = Permission::where('guard_name', 'api')->get();
                if ($role->id == 1) {
                    $this->command->info("Assigning all permissions to Developer role (ID 1)");
                } else {
                    $this->command->info("Assigning all permissions to Admin role for Partner ID 1");
                }
            } else {
                // Other Admin roles based on partner type
                $partner = Partner::find($role->partner_id);
                if ($partner && $role->name === 'Admin') {
                    if ($partner->partner_type == 'White-Label Partner (WL)') {
                        // Assign all permissions where is_host == 0
                        $permissions = Permission::where('guard_name', 'api')
                            ->where('is_host', 0)
                            ->get();
                        $this->command->info("Assigning White-Label permissions to Admin role for Partner ID {$partner->id}");
                    } elseif ($partner->partner_type == 'Certified Partner') {
                        // Assign all permissions where is_host == 0 && is_certified == 1
                        $permissions = Permission::where('guard_name', 'api')
                            ->where('is_host', 0)
                            ->where('is_certified', 1)
                            ->get();
                        $this->command->info("Assigning Certified permissions to Admin role for Partner ID {$partner->id}");
                    }
                }
            }

            if ($permissions->isNotEmpty()) {
                // 1. Sync permissions to the Role (this updates role_has_permissions)
                $role->syncPermissions($permissions);

                // 2. Get models associated with this role from model_has_roles
                $modelAssignments = DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->get();

                foreach ($modelAssignments as $assignment) {
                    $modelClass = $assignment->model_type;
                    $modelId = $assignment->model_id;

                    // Sync permissions to the individual model (this updates model_has_permissions)
                    try {
                        $model = $modelClass::find($modelId);
                        if ($model && method_exists($model, 'syncPermissions')) {
                            $model->syncPermissions($permissions);
                        }
                    } catch (\Throwable $e) {
                        $this->command->error("Failed to sync permissions for model {$modelClass} ID {$modelId}: " . $e->getMessage());
                    }
                }
            }
        }

        // Finalize by clearing cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command->info('UpdateAdminPermissionsSeeder completed!');
    }
}
