<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class MicrobiltDomainSettingsPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create or update the permissions
        $permissions = [
            [
                'name' => 'update microbilt settings',
                'is_host' => 1,
                'is_certified' => 0,
            ],
            [
                'name' => 'update domain configuration settings',
                'is_host' => 1,
                'is_certified' => 0,
            ],
        ];

        $targetPermissionObjects = [];
        foreach ($permissions as $p) {
            $permission = Permission::updateOrCreate(
                ['name' => $p['name'], 'guard_name' => 'api'],
                [
                    'module' => 'settings',
                    'is_host' => $p['is_host'],
                    'is_certified' => $p['is_certified'],
                ]
            );
            $targetPermissionObjects[] = $permission;
        }

        $this->command?->info('MicroBilt and Domain Settings permissions created/updated in permissions table.');

        // 2. Assign permissions to roles (Only roles with ID 1 and 2)
        $roles = Role::with('permissions')
            ->whereIn('id', [1, 2])
            ->get();

        foreach ($roles as $role) {
            // Give permissions to role
            $role->givePermissionTo($targetPermissionObjects);
            $this->command?->info("Assigned MicroBilt and Domain Settings permissions to Role: {$role->name}");

            // Sync to existing users of these roles by ID
            $users = User::whereHas('roles', function ($query) use ($role) {
                $query->where('id', $role->id);
            })->get();

            foreach ($users as $user) {
                if (method_exists($user, 'givePermissionTo')) {
                    $user->givePermissionTo($targetPermissionObjects);
                }
            }
        }

        // Force all users to refresh their session/tokens
        DB::table('users')->update(['is_refresh' => 1]);

        // Clear cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('MicrobiltDomainSettingsPermissionSeeder completed successfully!');
    }
}
