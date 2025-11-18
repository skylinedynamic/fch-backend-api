<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Http\Resources\FCH\Role\RoleResource;
use App\Traits\PaginatesApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Class RoleService
 *
 * Handles all role-related business logic:
 * - Listing, creating, updating, and deleting roles
 * - Assigning and syncing permissions with users
 *
 * @package App\Services
 */
class RoleService
{

    use PaginatesApi;

    public function list($partnerId, Request $request)
    {
        $query = Role::where('partner_id', $partnerId)->with('permissions');
        $paginator = $this->paginateBuilder($request, $query);
        return $this->respondWithPaginationResource($paginator, RoleResource::class, __('role_permission.role_list_retrieved'));
    }

    /**
     * Create a new role and optionally assign permissions.
     *
     * @param array $data
     * @return Role
     *
     * @throws \InvalidArgumentException
     */
    public function create(array $data): Role
    {
        if (empty($data['partner_id'])) {
            throw new \InvalidArgumentException('Partner ID is required to create a role.');
        }

        $data['guard_name'] = 'api';

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'],
            'partner_id' => $data['partner_id'],
        ]);

        if (!empty($data['permission_ids'])) {
            $permissions = Permission::whereIn('id', $data['permission_ids'])
                ->where('guard_name', 'api')
                ->get();

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->load('permissions');
    }

    /**
     * Update an existing role and synchronize its permissions.
     *
     * Updates both the role and all users assigned to it
     * to ensure permission consistency.
     *
     * @param Role $role
     * @param array $data
     * @param int|string $partnerId
     * @return Role
     */
    public function update(Role $role, array $data, $partnerId): Role
    {
        return DB::transaction(function () use ($role, $data, $partnerId) {
            // Step 1: Update role information
            $role->update([
                'name' => $data['name'] ?? $role->name,
                'guard_name' => 'api',
            ]);

            // Step 2: Sync permissions
            $permissions = collect();

            if (!empty($data['permission_ids'])) {
                $permissions = Permission::whereIn('id', $data['permission_ids'])
                    ->where('partner_id', $partnerId)
                    ->where('guard_name', 'api')
                    ->get();

                $role->syncPermissions($permissions);
            }

            // Step 3: Sync users under this role
            $userIds = DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->where('model_type', \App\Models\User\User::class)
                ->pluck('model_id');

            if ($userIds->isNotEmpty() && $permissions->isNotEmpty()) {
                // Remove existing user permissions
                DB::table('model_has_permissions')
                    ->whereIn('model_id', $userIds)
                    ->where('model_type', \App\Models\User\User::class)
                    ->delete();

                // Assign updated permissions to each user
                $insertData = [];
                foreach ($userIds as $userId) {
                    foreach ($permissions as $permission) {
                        $insertData[] = [
                            'permission_id' => $permission->id,
                            'model_type'    => \App\Models\User\User::class,
                            'model_id'      => $userId,
                        ];
                    }
                }

                DB::table('model_has_permissions')->insert($insertData);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load('permissions');
        });
    }

    /**
     * Delete a role and its related permissions and user assignments.
     *
     * @param Role $role
     * @return void
     */
    public function delete(Role $role): void
    {
        DB::transaction(function () use ($role) {
            // Remove related records
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();

            // Delete the role
            $role->delete();

            // Clear Spatie permission cache
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Assign permissions to a role and propagate them to all users under that role.
     *
     * @param Role $role
     * @param array $permissionIds
     * @param int|string $partnerId
     * @return Role
     */
    public function assignPermissions(Role $role, array $permissionIds, $partnerId): Role
    {
        return DB::transaction(function () use ($role, $permissionIds, $partnerId) {
            $permissions = Permission::whereIn('id', $permissionIds)
                ->where('partner_id', $partnerId)
                ->where('guard_name', 'api')
                ->get();

            // Assign permissions to role
            $role->syncPermissions($permissions);

            // Sync permissions for users assigned to this role
            $role->load('users');

            foreach ($role->users as $user) {
                DB::table('model_has_permissions')
                    ->where('model_type', get_class($user))
                    ->where('model_id', $user->id)
                    ->delete();

                foreach ($permissions as $permission) {
                    DB::table('model_has_permissions')->insert([
                        'permission_id' => $permission->id,
                        'model_type'   => get_class($user),
                        'model_id'     => $user->id,
                    ]);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load('permissions');
        });
    }
}
