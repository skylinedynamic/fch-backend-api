<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Models\Common\Role;
use App\Models\Common\Permission;
use App\Http\Resources\FCH\Role\RoleResource;
use App\Http\Resources\FCH\User\UserResource;
use App\Traits\PaginatesApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            ->orderBy('id')
            ->with(['permissions'])
            ->withCount('users');

        if (!Auth::user()->hasRole('Developer')) {
            $query->where('code', '!=', 'developer');
        }

        $roles = $query->get();
        return ApiResponse::success(RoleResource::collection($roles), __('role_permission.role_list_retrieved'));
        
    }

    public function list($partnerId, Request $request)
    {
        $query = Role::where('partner_id', $partnerId)
            ->orderBy('id')
            ->with(['permissions'])
            ->withCount('users');

        if (!Auth::user()->hasRole('Developer')) {
            $query->where('code', '!=', 'developer');
        }
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
                'code' => $data['code'] ?? strtolower(str_replace(' ', '_', $data['name'])),
                'description' => $data['description'] ?? '',
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
            return ApiResponse::error($e->getMessage(), 400);
        }
    }


    public function update(Role $role, array $data)
    {
        if ($role->code === 'developer' && !Auth::user()->hasRole('Developer')) {
            throw ApiResponse::error(__('role_permission.not_authorized_to_update_developer_role'), 403);
        }

        return DB::transaction(function () use ($role, $data) {
            $role->update([
                'name' => $data['name'] ?? $role->name,
                'description' => $data['description'] ?? $role->description,
            ]);
            $permissionIds = $data['permission_ids'] ?? [];
            $permissions = Permission::query()
                ->whereIn('id', $permissionIds)
                ->get();

            $role->syncPermissions($permissions);

            $role->load('users');
            
            foreach ($role->users as $user) {
                $user->syncPermissions($permissions);
                $user->is_refresh = true;
                $user->save();
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->load('permissions');
        });
    }


    public function deleteMany(array $ids)
    {
        $roles = Role::whereIn('id', $ids)->where('partner_id', Auth::user()->partner_id)->get();

        if ($roles->isEmpty()) {
            return ApiResponse::error(__('role_permission.role_not_found'), 404);
        }

        if (!Auth::user()->hasRole('Developer')) {
            $hasDeveloper = $roles->firstWhere('code', 'developer');
            if ($hasDeveloper) {
                 return ApiResponse::error(__('role_permission.not_authorized_to_delete_developer_role'), 403);
            }
        }

        $static = $roles->firstWhere('is_static', true);
        if ($static) {
            return ApiResponse::error(__('role_permission.role_static_cannot_delete'), 400);
        }

        $hasUsers = $roles->first(function ($role) {
            return $role->users()->exists();
        });
        if ($hasUsers) {
            return ApiResponse::error(__('role_permission.role_has_users_cannot_delete'), 400);
        }

        DB::transaction(function () use ($roles) {
            foreach ($roles as $role) {
                $role->syncPermissions([]);
                DB::table('model_has_roles')->where('role_id', $role->id)->delete();
                $role->delete();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });

        return ApiResponse::success(null, __('role_permission.role_deleted'));
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
