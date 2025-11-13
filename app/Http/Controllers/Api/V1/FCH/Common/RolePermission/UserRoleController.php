<?php
namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User\User;
use App\Models\Common\Role;
use App\Models\Common\Permission;

class UserRoleController extends Controller
{
     /**
     * Assign Role
     *
     */
    public function assignRoles(Request $request, User $user)
    {
        $request->validate(['role_ids' => 'required|array']);
        $businessId = $request->user()->business_id;
        $roles = Role::whereIn('id', $request->role_ids)->where('business_id', $businessId)->get();
        $user->syncRoles($roles);
        return response()->json($user->roles);
    }

    /**
     * Assign Permissions
     *
     */

    public function assignPermissions(Request $request, User $user)
    {
        $request->validate(['permission_ids' => 'required|array']);
        $businessId = $request->user()->business_id;
        $perms = Permission::whereIn('id', $request->permission_ids)->where('business_id', $businessId)->get();
        $user->syncPermissions($perms);
        return response()->json($user->permissions);
    }
}
