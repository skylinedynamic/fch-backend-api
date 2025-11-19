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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
