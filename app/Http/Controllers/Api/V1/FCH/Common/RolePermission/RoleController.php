<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use App\Models\Common\Role;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\RolePermission\RoleService;

/**
 * Class RoleController
 *
 * Handles role management, including creating, updating,
 * deleting, and assigning permissions to roles.
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
     * Get List
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $businessId = $request->user()->business_id;

        return response()->json($this->roleService->list($businessId));
    }

    /**
     * Store Role
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'permission_ids' => 'array|nullable',
        ]);

        $businessId = $request->user()->business_id;

        // 🔍 Check for duplicate role within the same business
        $existingRole = Role::where('name', $request->name)
            ->where('business_id', $businessId)
            ->first();

        if ($existingRole) {
            return response()->json([
                'status'  => false,
                'message' => 'The role name already exists.',
            ], 409); // 409 Conflict
        }

        // ✅ Create new role
        $data = $request->only(['name', 'guard_name', 'permission_ids']);
        $data['business_id'] = $businessId;
        $data['guard_name'] = $data['guard_name'] ?? 'api';

        $role = $this->roleService->create($data);

        return response()->json([
            'status'  => true,
            'message' => 'Role created successfully',
            'data'    => $role,
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
        return response()->json($role->load('permissions'));
    }

    /**
     * Update Role
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'permission_ids' => 'array',
        ]);

        $businessId = auth()->user()->business_id;

        $role = Role::where('id', $id)
            ->where('business_id', $businessId)
            ->first();

        if (!$role) {
            return response()->json([
                'status' => false,
                'message' => 'Role not found.',
            ], 404);
        }

        $updatedRole = $this->roleService->update($role, $validated, $businessId);

        return response()->json([
            'status' => true,
            'message' => 'Role updated successfully.',
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
            'status'  => true,
            'message' => 'Role deleted successfully.',
        ], 200);
    }

    /**
     * Assign permissions
     *
     * @param Request $request
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignPermissions(Request $request, Role $role)
    {
        $request->validate([
            'permission_ids' => 'required|array',
        ]);

        $businessId = $request->user()->business_id;

        $role = $this->roleService->assignPermissions(
            $role,
            $request->permission_ids,
            $businessId
        );

        return response()->json([
            'status' => true,
            'message' => 'Permissions assigned successfully.',
            'data' => $role,
        ]);
    }
}
