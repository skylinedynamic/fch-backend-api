<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Models\Partner\Partner;
use Spatie\Permission\PermissionRegistrar;

class GiveNonHostPartnersSubscriptionPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::preventLazyLoading(false);

        // 1) Forget cached permissions before starting
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2) Load subscription permissions from config/permissions.php and ensure they exist in DB
        $subscriptionConfig = config('permissions.subscriptions.permissions', []);
        $permissionNames = [];

        foreach ($subscriptionConfig as $perm) {
            if (is_array($perm)) {
                $permissionName = $perm['name'];
                $isCertified = $perm['is_certified'] ?? 0;
                $isHost = $perm['is_host'] ?? 0;
            } else {
                $permissionName = $perm;
                $isCertified = 0;
                $isHost = 0;
            }

            $permissionNames[] = $permissionName;

            Permission::updateOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ],
                [
                    'module' => 'subscriptions',
                    'is_host' => $isHost,
                    'is_certified' => $isCertified,
                ]
            );
        }

        $subscriptionPermissions = Permission::whereIn('name', $permissionNames)
            ->where('guard_name', 'api')
            ->get();

        $this->command->info('Created/Updated subscription permissions in database with is_host = 0.');

        // 3) Find all Admin roles (including Developer role ID 1 and partner Admin roles)
        $adminRoles = Role::with('permissions')
            ->where('name', 'Admin')
            ->where('partner_id', '!=', 1)
            ->get();

        $this->command->info("Found {$adminRoles->count()} Admin role(s) to update.");

        foreach ($adminRoles as $role) {
            // Give subscription permissions to the role
            $role->givePermissionTo($subscriptionPermissions);

            // Sync permissions to all models (users) assigned to this role in model_has_roles
            $modelAssignments = DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->get();

            foreach ($modelAssignments as $assignment) {
                $modelClass = $assignment->model_type;
                $modelId = $assignment->model_id;

                try {
                    $user = $modelClass::with('permissions')->find($modelId);
                    if ($user && method_exists($user, 'givePermissionTo')) {
                        $user->givePermissionTo($subscriptionPermissions);
                    }
                } catch (\Throwable $e) {
                    $this->command->error("Failed to assign permissions to model {$modelClass} ID {$modelId}: " . $e->getMessage());
                }
            }
        }

        // 4) Forget cached permissions again after update
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info('✅ Subscription permissions successfully granted to non-host partners, Admin roles, and associated users!');
    }
}
