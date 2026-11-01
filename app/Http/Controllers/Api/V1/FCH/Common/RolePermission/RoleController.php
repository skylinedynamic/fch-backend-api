<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use App\Models\Common\Role;
use App\Http\Requests\Api\V1\FCH\Common\RolePermission\DeleteRolesRequest;
use App\Helpers\ApiResponse;
use App\Http\Resources\FCH\User\UserResource;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\RolePermission\RoleService;
use App\Http\Requests\Api\V1\FCH\Common\RolePermission\StoreRoleRequest;
use App\Http\Requests\Api\V1\FCH\Common\RolePermission\UpdateRoleRequest;
use App\Http\Requests\Api\V1\FCH\Common\RolePermission\FetchRoleRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Class RoleController
 *
 * @package App\Http\Controllers\Api\V1\Global\RolePermission
 */
class RoleController extends Controller
{
    /**
     * RoleController constructor.
     *
     * @param RoleService $service
     */
    public function __construct(protected RoleService $roleService)
    {
    }

    /**
     * Get All Roles
     *
     */
    public function index()
    {
        $partnerId = Auth::user()->partner_id;
        return $this->roleService->getAll($partnerId);
    }


    /**
     * Roles with Pagination
     *
     */
    public function fetchRoles(FetchRoleRequest $request)
    {
        $partnerId = Auth::user()->partner_id;
        return $this->roleService->list($partnerId, $request);
    }

    /**
     * Store Role
     *
     */
    public function store(StoreRoleRequest $request)
    {
        $partnerId = $request->user()->partner_id;

        $data = $request->only(['name','description', 'permission_ids']);
        $data['partner_id'] = $partnerId;

        return $this->roleService->create($data);

    }

    /**
     * Details Role
     *
     */
    public function show(Role $role)
    {
        // Roles belong to a partner; another partner's role does not exist here.
        if ((int) $role->partner_id !== (int) Auth::user()->partner_id) {
            return ApiResponse::error(__('role_permission.role_not_found'), 404);
        }

        if ($role->code === 'developer' && !Auth::user()->hasRole('Developer')) {
            return ApiResponse::error(__('role_permission.role_not_found'), 404);
        }

        $role->load(['permissions', 'users.entity']);

        $payload = [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            // A system role (Admin, Developer): shown read-only (R2 BUG-011).
            'is_static' => (bool) $role->is_static,
            'permission' => $role->permissions->pluck('name','id')->unique()->toArray(),
            'users' => UserResource::collection(
                $role->users->reject(function ($user) {
                    return $user->hasRole('Developer') && !Auth::user()->hasRole('Developer');
                })
            )->toArray(request()),
        ];

        return ApiResponse::success($payload, __('role_permission.role_details'));
    }

    /**
     * Update Role
     *
     */
    public function update(UpdateRoleRequest $request, Role $role)
    {
        if ((int) $role->partner_id !== (int) Auth::user()->partner_id) {
            return ApiResponse::error(__('role_permission.role_not_found'), 404);
        }

        $updatedRole = $this->roleService->update($role, $request->validated());
        
        return response()->json([
            'status' => true,
            'message' => __('role_permission.role_updated'),
            'data' => $updatedRole,
        ]);
    }

    /**
     * Remove Role
     *
     * @param Role $role
     */
    public function destroy(DeleteRolesRequest $request)
    {
        return $this->roleService->deleteMany($request->ids);
    }
}
