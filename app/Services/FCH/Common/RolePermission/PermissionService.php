<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Helpers\ApiResponse;
use App\Models\Common\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\FCH\Permission\PermissionResource;
use App\Models\Partner\Partner;

class PermissionService
{
    /**
     * The permissions a user may hand out: all of them for the host's team and
     * developers, the non-host ones for other partners, and of those only the
     * certified ones for a certified partner. The developer-only ones
     * (Business Insights) are listed to Developers alone. The picker lists
     * this set and every role/permission write is held to it.
     */
    public function assignableQuery($user): Builder
    {
        $query = Permission::query()->where('guard_name', 'api');

        $isDeveloper = $user->isDeveloper();
        $isHostPartner = $user->partner?->is_host ?? false;

        if (!$isDeveloper) {
            $query->withoutDeveloperOnly();
        }

        if (!$isDeveloper && !$isHostPartner) {
            $query->where('is_host', 0);
            if ($user->partner?->partner_type == Partner::PARTNER_TYPE_CERTIFIED) {
                $query->where('is_certified', 1);
            }
        }

        return $query;
    }

    /**
     * What $user may give to a role or a user: the assignable set, less the
     * developer-only permissions (Business Insights) unless the receiving
     * role or user is the host's. Only a Developer has them to give at all
     * (assignableQuery), and never to another partner.
     */
    public function grantableQuery($user, bool $toHost): Builder
    {
        $query = $this->assignableQuery($user);

        if (!$toHost) {
            $query->withoutDeveloperOnly();
        }

        return $query;
    }

    /**
     * $granted plus the developer-only permissions $current already holds
     * when $user may not grant them: whoever cannot see them (a host admin)
     * must not strip what a Developer gave by saving the role or user.
     *
     * @param  Collection<int, Permission>  $granted
     * @param  Collection<int, Permission>  $current
     * @return Collection<int, Permission>
     */
    public function keepingDeveloperOnly($user, Collection $granted, Collection $current): Collection
    {
        if ($user->isDeveloper()) {
            return $granted;
        }

        return $granted
            ->merge($current->filter(fn (Permission $permission) => (bool) $permission->is_developer))
            ->unique('id')
            ->values();
    }

    /** Whether the partner $partnerId is the host - the only one its roles and users may hold developer-only permissions. */
    public function isHostPartner(?int $partnerId): bool
    {
        return $partnerId !== null && (bool) Partner::whereKey($partnerId)->value('is_host');
    }

    /**
     * Get all permissions (id, name)
     */
    public function getAll(Request $request)
    {
        $isGroupBy = $request->boolean('is_groupby');

        $query = $this->assignableQuery(Auth::user())
            ->select('id', 'name', 'module')
            // The Business Insights (developer-only) group first, then the
            // rest in the order they were added.
            ->orderByDesc('is_developer')
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
