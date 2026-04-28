<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UpdateUserRolesPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        // Delete old settings permissions
        DB::table('permissions')
            ->whereIn('name', ['view settings', 'create settings', 'update settings', 'delete settings'])
            ->where('guard_name', 'api')
            ->delete();

        // New permissions to insert
        $permissions = [
            // settings module
            ['name' => 'update payment settings', 'guard_name' => 'api', 'module' => 'settings', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'update partner agreement settings', 'guard_name' => 'api', 'module' => 'settings', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'update borrower agreement settings', 'guard_name' => 'api', 'module' => 'settings', 'is_certified' => 1, 'is_host' => 0, 'created_at' => $now, 'updated_at' => $now],

            // credit builder module
            ['name' => 'view credit accounts', 'guard_name' => 'api', 'module' => 'credit builder', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'create credit accounts', 'guard_name' => 'api', 'module' => 'credit builder', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'update credit accounts', 'guard_name' => 'api', 'module' => 'credit builder', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'delete credit accounts', 'guard_name' => 'api', 'module' => 'credit builder', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['name' => 'view change requests', 'guard_name' => 'api', 'module' => 'intake and review', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'update change requests', 'guard_name' => 'api', 'module' => 'intake and review', 'is_certified' => 0, 'is_host' => 1, 'created_at' => $now, 'updated_at' => $now],

        ];

        // Insert new permissions
        DB::table('permissions')->insertOrIgnore($permissions);

        // Get all permission IDs for mapping, including specifically requested resource permissions
        $permissionNames = array_column($permissions, 'name');
        array_push($permissionNames, 'view resources', 'create resources', 'update resources', 'delete resources');

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->where('guard_name', 'api')
            ->pluck('id');

        // Assign to Roles 1 and 2
        $rolePermissions = [];
        foreach ($permissionIds as $permissionId) {
            $rolePermissions[] = ['permission_id' => $permissionId, 'role_id' => 1];
            $rolePermissions[] = ['permission_id' => $permissionId, 'role_id' => 2];
        }
        DB::table('role_has_permissions')->insertOrIgnore($rolePermissions);

        // Assign directly to users of Roles 1 and 2
        $userIdsMapping = DB::table('model_has_roles')
            ->whereIn('role_id', [1, 2])
            ->get();

        $userPermissions = [];
        foreach ($userIdsMapping as $mapping) {
            foreach ($permissionIds as $permissionId) {
                $userPermissions[] = [
                    'permission_id' => $permissionId,
                    'model_type' => $mapping->model_type,
                    'model_id' => $mapping->model_id
                ];
            }
        }

        if (!empty($userPermissions)) {
            DB::table('model_has_permissions')->insertOrIgnore($userPermissions);
        }

        // 3. Specific assignment for other admin roles based on Partner type criteria
        $adminRoles = DB::table('roles')
            ->where('code', 'admin')
            ->whereNotIn('id', [1, 2])
            ->get();

        $adminRoleIds = $adminRoles->pluck('id')->toArray();

        if (!empty($adminRoleIds)) {
            $adminUsers = DB::table('model_has_roles')
                ->whereIn('role_id', $adminRoleIds)
                ->join('users', 'model_has_roles.model_id', '=', 'users.id')
                ->select('users.id as user_id', 'users.partner_id as user_partner_id', 'model_has_roles.role_id')
                ->get();

            $partnerIds = $adminRoles->pluck('partner_id')->merge($adminUsers->pluck('user_partner_id'))->filter()->unique()->toArray();
            $partners = DB::table('partners')->whereIn('id', $partnerIds)->pluck('partner_type', 'id');

            // Evaluator to get Partner constants for accurate strict filtering
            $certifiedType = class_exists(\App\Models\Partner\Partner::class) && defined('\App\Models\Partner\Partner::PARTNER_TYPE_CERTIFIED')
                ? \App\Models\Partner\Partner::PARTNER_TYPE_CERTIFIED
                : 'Certified Partner';

            $whiteLabelType = class_exists(\App\Models\Partner\Partner::class) && defined('\App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL')
                ? \App\Models\Partner\Partner::PARTNER_TYPE_WHITE_LABEL
                : 'White-Label Partner (WL)';

            // Get full details of permissions to read is_host & is_certified
            $newPermissionsDetails = DB::table('permissions')
                ->whereIn('id', $permissionIds)
                ->get();

            $adminRolePerms = [];
            $adminUserPerms = [];

            $hasPermissionByPartnerLogic = function ($pType, $p) use ($certifiedType, $whiteLabelType) {
                if ($pType == $certifiedType) {
                    return $p->is_host == 0 && $p->is_certified == 1;
                } elseif ($pType == $whiteLabelType) {
                    return $p->is_host == 0;
                } else {
                    return $p->is_host == 0; // Fallback rule based on PartnerService logic
                }
            };

            // Process Roles
            foreach ($adminRoles as $role) {
                if (!$role->partner_id)
                    continue;
                $pType = $partners->get($role->partner_id);

                foreach ($newPermissionsDetails as $p) {
                    if ($hasPermissionByPartnerLogic($pType, $p)) {
                        $adminRolePerms[] = ['permission_id' => $p->id, 'role_id' => $role->id];
                    }
                }
            }

            // Process Users
            foreach ($adminUsers as $u) {
                if (!$u->user_partner_id)
                    continue;
                $pType = $partners->get($u->user_partner_id);

                foreach ($newPermissionsDetails as $p) {
                    if ($hasPermissionByPartnerLogic($pType, $p)) {
                        $adminUserPerms[] = [
                            'permission_id' => $p->id,
                            'model_type' => 'App\\Models\\User',
                            'model_id' => $u->user_id
                        ];
                    }
                }
            }

            if (!empty($adminRolePerms)) {
                DB::table('role_has_permissions')->insertOrIgnore($adminRolePerms);
            }
            if (!empty($adminUserPerms)) {
                DB::table('model_has_permissions')->insertOrIgnore($adminUserPerms);
            }
        }

        // Optionally clear the cache if using Spatie Permission
        if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        }

        // Force all users to refresh their state to inherit the new permission architecture
        DB::table('users')->update(['is_refresh' => 1]);
    }
}
