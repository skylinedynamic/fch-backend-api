<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Http\Resources\FCH\Role\RoleResource;
use App\Http\Resources\FCH\User\UserResource;
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

    public function getAll($partnerId)
    {
        $query = Role::where('partner_id', $partnerId)
            ->orderByDesc('id')
            ->with(['permissions'])
            ->withCount('users')
            ->get();
        return ApiResponse::success(RoleResource::collection($query), __('role_permission.role_list_retrieved'));
        
    }

    public function list($partnerId, Request $request)
    {
        $query = Role::where('partner_id', $partnerId)
            ->orderByDesc('id')
            ->with(['permissions'])
            ->withCount('users');
        $paginator = $this->paginateBuilder($query, $request);
        return $this->respondWithPaginationResource($paginator, RoleResource::class, __('role_permission.role_list_retrieved'));
    }

 
    public function create(array $data)
    {
        DB::beginTransaction();
        try {
            if (empty($data['partner_id'])) {
                throw ApiResponse::error(__('role_permission.partner_id_required'));
            }

            $data['guard_name'] = 'api';
            $data['name'] = trim((string) ($data['name'] ?? ''));
            $permissionIds = array_values(array_unique(array_filter(array_map('intval', $data['permission_ids'] ?? []), function ($id) { return $id > 0; })));
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => $data['guard_name'],
                'partner_id' => $data['partner_id'],
            ]);

            if (!empty($permissionIds)) {
                $permissions = Permission::whereIn('id', $permissionIds)
                    ->where('guard_name', 'api')
                    ->get();
                if ($permissions->isNotEmpty()) {
                    $role->syncPermissions($permissions);
                }
            }

            DB::commit();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return ApiResponse::success($role, __('role_permission.role_created'), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error(__('role_permission.role_create_error'), 400);
        }
    }


    public function update(Role $role, array $data)
    {
        return DB::transaction(function () use ($role, $data) {
            $role->update([
                'name' => $data['name'] ?? $role->name,
            ]);
            $permissionIds = $data['permission_ids'] ?? [];
            $permissions = Permission::query()
                ->whereIn('id', $permissionIds)
                ->get();

            $role->syncPermissions($permissions);

            $role->load('users');
            
            foreach ($role->users as $user) {
                $user->syncPermissions($permissions);
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
