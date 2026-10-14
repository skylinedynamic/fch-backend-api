<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Models\Common\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\FCH\Permission\PermissionResource;
use App\Models\Partner\Partner;

class PermissionService
{
    /**
     * The permissions a user may hand out: all of them for the host's team and
     * developers, the non-host ones for other partners, and of those only the
     * certified ones for a certified partner. The picker lists this set and
     * every role/permission write is held to it.
     */
    public function assignableQuery($user): Builder
    {
        $query = Permission::query()->where('guard_name', 'api');

        $isDeveloper = $user->hasRole('Developer');
        $isHostPartner = $user->partner?->is_host ?? false;

        if (!$isDeveloper && !$isHostPartner) {
            $query->where('is_host', 0);
            if ($user->partner?->partner_type == Partner::PARTNER_TYPE_CERTIFIED) {
                $query->where('is_certified', 1);
            }
        }

        return $query;
    }

    /**
     * Get all permissions (id, name)
     */
    public function getAll(Request $request)
    {
        $isGroupBy = $request->boolean('is_groupby');

        $query = $this->assignableQuery(Auth::user())
            ->select('id', 'name', 'module')
            ->orderBy('id', 'asc');

        $permissions = $query->get();

        if ($isGroupBy) {
            $grouped = $permissions
                ->groupBy('module')
                ->map(function ($items, $module) {
                    return [
                        'module' => $module,
                        'permissions' => $items->map(function ($perm) {
                            return [
                                'id' => $perm->id,
                                'name' => $perm->name,
                            ];
                        })->values(),
                    ];
                })->values();

            return ApiResponse::success($grouped, __('permission.permission_list_retrieved'));
        }

        return ApiResponse::success(
            PermissionResource::collection($permissions),
            __('permission.permission_list_retrieved')
        );
    }
}
