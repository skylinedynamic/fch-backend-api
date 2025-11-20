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
use App\Http\Requests\Api\V1\FCH\Common\RolePermission\AssignPermissionsRequest;
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

        $data = $request->only(['name', 'permission_ids']);
        $data['partner_id'] = $partnerId;

        return $this->roleService->create($data);

    }

    /**
     * Details Role
     *
     */
    public function show(Role $role)
    {
        $role->load(['permissions', 'users']);

        $payload = [
            'id' => $role->id,
            'name' => $role->name,
            'permission' => $role->permissions->pluck('name','id')->unique()->toArray(),
            'users' => UserResource::collection($role->users)->toArray(request()),
        ];

        return ApiResponse::success($payload, __('role_permission.role_details'));
    }

    /**
     * Update Role
     *
     */
    public function update(UpdateRoleRequest $request, Role $role)
    {
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

    /**
     * Assign permissions
     *
     * @param AssignPermissionsRequest $request
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignPermissions(AssignPermissionsRequest $request, Role $role)
    {
        $partnerId = $request->user()->partner_id;

        $role = $this->roleService->assignPermissions(
            $role,
            $request->permission_ids,
            $partnerId
        );

        return response()->json([
            'status' => true,
            'message' => __('role_permission.role_updated'), // You can reuse this for assigning permissions
            'data' => $role,
        ]);
    }
}
