<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class TaskReminderPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Clear cached permissions before seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2. Load task & reminder permissions from config/permissions.php and create/update in DB
        $reminderConfig = config('permissions.tasks & reminders.permissions', []);
        $permissionNames = [];

        foreach ($reminderConfig as $p) {
            $permissionName = is_array($p) ? $p['name'] : $p;
            $isCertified = is_array($p) ? ($p['is_certified'] ?? 0) : 0;
            $isHost = is_array($p) ? ($p['is_host'] ?? 0) : 0;

            $permissionNames[] = $permissionName;

            Permission::updateOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ],
                [
                    'module' => 'tasks & reminders',
                    'is_host' => $isHost,
                    'is_certified' => $isCertified,
                ]
            );

            $this->command?->info("Created/Updated permission: {$permissionName}");
        }

        // 3. Find all Admin and Developer roles
        $roles = Role::whereIn('code', ['admin', 'developer'])
            ->orWhereIn('name', ['Admin', 'Developer'])
            ->orWhere('id', 1)
            ->get();

        $this->command?->info("Found {$roles->count()} Admin/Developer role(s) to grant permission.");

        foreach ($roles as $role) {
            foreach ($permissionNames as $permName) {
                if (!$role->hasPermissionTo($permName)) {
                    $role->givePermissionTo($permName);
                }
            }

            $this->command?->info("Granted permissions (" . implode(', ', $permissionNames) . ") to Role: {$role->name} (ID: {$role->id}, Partner ID: " . ($role->partner_id ?? 'N/A') . ")");

            // Assign permission to users having this role (model_has_permissions)
            $modelAssignments = DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->get();

            foreach ($modelAssignments as $assignment) {
                $modelClass = $assignment->model_type;
                $modelId = $assignment->model_id;

                try {
                    $user = $modelClass::find($modelId);
                    if ($user && method_exists($user, 'givePermissionTo')) {
                        foreach ($permissionNames as $permName) {
                            if (!$user->hasPermissionTo($permName)) {
                                $user->givePermissionTo($permName);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->command?->error("Failed to assign permission to model {$modelClass} ID {$modelId}: " . $e->getMessage());
                }
            }
        }

        // 4. Force refresh user states and clear permission cache
        DB::table('users')->update(['is_refresh' => 1]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✅ TaskReminderPermissionSeeder completed successfully!');
    }
}
