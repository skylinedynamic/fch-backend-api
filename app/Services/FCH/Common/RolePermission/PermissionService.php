<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Models\Common\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\FCH\Permission\PermissionResource;

class PermissionService
{
    /**
     * Get all permissions (id, name)
     */
    public function getAll(Request $request)
    {
        $isGroupBy = $request->boolean('is_groupby');
        $user = Auth::user();
        $isDeveloper = $user->hasRole('Developer');
        $isHostPartner = $user->partner?->is_host ?? false;

        $query = Permission::query()
            ->select('id', 'name', 'module')
            ->orderBy('id', 'asc');

        if (!$isDeveloper && !$isHostPartner) {
            $query->where('is_host', 0);
        }

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
