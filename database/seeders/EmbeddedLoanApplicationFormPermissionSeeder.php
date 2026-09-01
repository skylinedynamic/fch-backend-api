<?php

namespace Database\Seeders;

use App\Models\Common\Permission;
use App\Models\Common\Role;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class EmbeddedLoanApplicationFormPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create or update the "enbedded loan application form" permissions
        //    from config/permissions.php in the permissions table.
        $moduleKey = 'enbedded loan application form';
        $moduleConfig = config("permissions.{$moduleKey}");

        if (empty($moduleConfig['permissions'])) {
            $this->command?->error("Module `{$moduleKey}` not found in config/permissions.php.");
            return;
        }

        foreach ($moduleConfig['permissions'] as $p) {
            Permission::updateOrCreate(
                ['name' => $p['name'], 'guard_name' => 'api'],
                [
                    'module' => $moduleKey,
                    'is_host' => $p['is_host'] ?? 0,
                    'is_certified' => $p['is_certified'] ?? 0,
                ]
            );
        }

        $this->command?->info('Embedded loan application form permissions seeded/updated in permissions table.');

        // 2. Resolve the seeded permissions
        $permissionNames = collect($moduleConfig['permissions'])->pluck('name')->all();
        $targetPermissions = Permission::whereIn('name', $permissionNames)
            ->where('guard_name', 'api')
            ->get();

        if ($targetPermissions->isEmpty()) {
            $this->command?->error('Target permissions not found after seeding.');
            return;
        }

        // 3. Get every Admin and Developer role (each partner has its own).
        //    Eager-load `permissions` — givePermissionTo() reads it directly and
        //    lazy loading is disabled in this app.
        $roles = Role::with('permissions')
            ->where('guard_name', 'api')
            ->whereIn('name', ['Admin', 'Developer'])
            ->get();

        $affectedUserIds = [];

        foreach ($roles as $role) {
            // Give the permissions to the role.
            $role->givePermissionTo($targetPermissions);
            $this->command?->info("Assigned embedded loan application form permissions to Role: {$role->name} (Partner ID: " . ($role->partner_id ?? 'N/A') . ")");

            // Get every user who owns this specific role (scoped by partner to
            // avoid cross-tenant matches on the shared 'Admin'/'Developer' names).
            // Eager-load `permissions` for the same reason as above.
            $users = User::with('permissions')
                ->where('partner_id', $role->partner_id)
                ->whereHas('roles', function ($query) use ($role) {
                    $query->where('id', $role->id);
                })
                ->get();

            foreach ($users as $user) {
                // Also assign the permissions directly to the user model.
                $user->givePermissionTo($targetPermissions);
                $affectedUserIds[] = $user->id;
                $this->command?->info("Assigned permissions directly to User: {$user->email}");
            }
        }

        // 4. Force every affected user to refresh their permissions/session state.
        $affectedUserIds = array_unique($affectedUserIds);
        if (!empty($affectedUserIds)) {
            DB::table('users')->whereIn('id', $affectedUserIds)->update(['is_refresh' => 1]);
            $this->command?->info('Marked ' . count($affectedUserIds) . ' affected user(s) for refresh (is_refresh = 1).');
        }

        // Finalize by clearing cache again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('EmbeddedLoanApplicationFormPermissionSeeder completed successfully!');
    }
}
