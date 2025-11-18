<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use App\Models\Common\Role;
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
     * @param StoreRoleRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreRoleRequest $request)
    {
        $partnerId = $request->user()->partner_id;

        // ✅ Create new role
        $data = $request->only(['name', 'guard_name', 'permission_ids']);
        $data['partner_id'] = $partnerId;
        $data['guard_name'] = $data['guard_name'] ?? 'api';

        $role = $this->roleService->create($data);

        return response()->json([
            'status' => true,
            'message' => __('role_permission.role_created'),
            'data' => $role,
        ], 201);
    }

    /**
     * Details Role
     *
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Role $role)
    {
        return response()->json([
            'status' => true,
            'message' => __('role_permission.role_details'),
            'data' => $role->load('permissions'),
        ]);
    }

    /**
     * Update Role
     *
     * @param UpdateRoleRequest $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateRoleRequest $request, int $id)
    {
        $partnerId = auth()->user()->partner_id;

        $role = Role::where('id', $id)
            ->where('partner_id', $partnerId)
            ->first();

        $updatedRole = $this->roleService->update($role, $request->validated(), $partnerId);

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
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Role $role)
    {
        $this->roleService->delete($role);

        return response()->json([
            'status' => true,
            'message' => __('role_permission.role_deleted'),
        ], 200);
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
