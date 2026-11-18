<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Models\Common\Role;
use App\Http\Resources\FCH\Role\RoleResource;
use App\Http\Resources\FCH\User\UserResource;
use App\Traits\PaginatesApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;

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

    public function __construct(
        protected ActivityLogService $activityLogService
    ) {}

    public function getAll($partnerId)
    {
        $query = Role::where('partner_id', $partnerId)
            ->orderBy('id')
            ->with(['permissions'])
            ->withCount('users');

        if (!Auth::user()->isDeveloper()) {
            $query->where('code', '!=', Role::CODE_DEVELOPER);
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

        if (!Auth::user()->isDeveloper()) {
            $query->where('code', '!=', Role::CODE_DEVELOPER);
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
                // The new role is the caller's partner's: developer-only
                // permissions only when that is the host (and the caller a Developer).
                $permissionService = app(PermissionService::class);
                $permissions = $permissionService->grantableQuery(Auth::user(), $permissionService->isHostPartner($role->partner_id))
                    ->whereIn('id', $permissionIds)
                    ->get();
                if ($permissions->isNotEmpty()) {
                    $role->syncPermissions($permissions);
                }
            }
            
            // Log System Event
            $this->activityLogService->logModelEvent(
                $role,
                ActivityLog::LOG_TYPE_ROLE_CREATE
            );

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
        if ($role->isDeveloper() && !Auth::user()->isDeveloper()) {
            throw ApiResponse::error(__('role_permission.not_authorized_to_update_developer_role'), 403);
        }

        return DB::transaction(function () use ($role, $data) {
            $role->update([
                'name' => $data['name'] ?? $role->name,
                'description' => $data['description'] ?? $role->description,
            ]);
            $permissionIds = $data['permission_ids'] ?? [];
            $permissionService = app(PermissionService::class);
            $granted = $permissionService->grantableQuery(Auth::user(), $permissionService->isHostPartner($role->partner_id))
                ->whereIn('id', $permissionIds)
                ->get();
            // Someone who cannot see the developer-only ones keeps those the role has.
            $permissions = $permissionService->keepingDeveloperOnly(Auth::user(), $granted, $role->permissions()->get());

            $role->syncPermissions($permissions);

            $role->load('users');

            foreach ($role->users as $user) {
                $user->syncPermissions($permissions);
            }

            $this->markRoleUsersForRefresh($role);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            // Log System Event
            $this->activityLogService->logModelEvent(
                $role,
                ActivityLog::LOG_TYPE_ROLE_UPDATE
            );

            return $role->load('permissions');
        });
    }


    public function deleteMany(array $ids)
    {
        $roles = Role::whereIn('id', $ids)->where('partner_id', Auth::user()->partner_id)->get();

        if ($roles->isEmpty()) {
            return ApiResponse::error(__('role_permission.role_not_found'), 404);
        }

        if (!Auth::user()->isDeveloper()) {
            $hasDeveloper = $roles->firstWhere('code', Role::CODE_DEVELOPER);
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
     * Whenever a role's permissions change, every user who owns that role must
     * re-fetch their permissions/roles on their next request. Flag them all
     * with a single bulk update rather than saving each user model.
     *
     * @param Role $role
     * @return void
     */
    protected function markRoleUsersForRefresh(Role $role): void
    {
        $role->loadMissing('users');

        $userIds = $role->users->pluck('id');

        if ($userIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $userIds)->update(['is_refresh' => true]);
        }
    }
}
