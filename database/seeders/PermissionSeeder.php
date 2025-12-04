<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Clear cached permissions before seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2) Get all modules from config
        $permissionModules = config('permissions');

        if (empty($permissionModules)) {
            $this->command->warn('No permissions found in config/permissions.php');

            return;
        }

        // 3) Loop through each module and create permissions
        foreach ($permissionModules as $moduleName => $moduleData) {
            // Get is_host flag (default to 0 if not set)
            $isHost = $moduleData['is_host'] ?? 0;

            // Get permissions array
            $permissions = $moduleData['permissions'] ?? [];

            if (empty($permissions)) {
                $this->command->warn("No permissions found for module: {$moduleName}");

                continue;
            }

            // Create each permission
            foreach ($permissions as $permissionName) {
                $module = '';
                if (Str::contains($permissionName, ' ')) {
                    $module = (string) Str::of($permissionName)->after(' ')->trim();
                }

                Permission::updateOrCreate(
                    [
                        'name' => $permissionName,
                        'guard_name' => 'api',
                    ],
                    [
                        'module' => $module,
                        'is_host' => $isHost,
                    ]
                );
            }
        }

        $this->command->info('✅ All permissions seeded successfully!');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
