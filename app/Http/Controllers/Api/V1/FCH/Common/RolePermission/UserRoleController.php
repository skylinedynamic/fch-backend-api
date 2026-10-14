<?php
namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\RolePermission\PermissionService;
use Illuminate\Http\Request;
use App\Models\User\User;
use App\Models\Common\Role;

class UserRoleController extends Controller
{
     /**
     * Assign Role
     *
     */
    public function assignRoles(Request $request, User $user)
    {
        $request->validate(['role_ids' => 'required|array']);

        if (!$this->isOwnPartnersUser($request, $user)) {
            return ApiResponse::notFound(__('user.user_not_found'));
        }

        $partnerId = $request->user()->partner_id;
        $roles = Role::whereIn('id', $request->role_ids)->where('partner_id', $partnerId)->get();
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

        if (!$this->isOwnPartnersUser($request, $user)) {
            return ApiResponse::notFound(__('user.user_not_found'));
        }

        $perms = app(PermissionService::class)->assignableQuery($request->user())
            ->whereIn('id', $request->permission_ids)
            ->get();
        $user->syncPermissions($perms);
        return response()->json($user->permissions);
    }

    /**
     * A partner manages the roles and permissions of its own people only - the
     * user comes straight from the URL, so this is the only thing standing
     * between a partner and another partner's (or the host's) accounts.
     */
    private function isOwnPartnersUser(Request $request, User $user): bool
    {
        $caller = $request->user();

        return $caller->partner_id
            && (int) $user->partner_id === (int) $caller->partner_id
            && $user->user_type !== User::BORROWER;
    }
}
