<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Models\User\User;
use App\Models\Common\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Log;

class UserPermissionService
{
    /**
     * Sync permissions to a user by permission IDs or Names.
     *
     * @param  \App\Models\User\User  $user
     * @param  array  $permissions  // array of IDs or Names
     * @param  int  $businessId
     * @return \App\Models\User\User
     */
    public function syncPermissionsToUser(User $user, array $permissions, int $businessId): User
    {
        if (empty($permissions)) {
            // Remove all permissions if empty array
            $user->syncPermissions([]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            return $user->load('permissions');
        }

        // Detect if permissions array contains IDs or names
        $first = $permissions[0] ?? null;

        if (is_numeric($first)) {
            // IDs case
            $perms = Permission::whereIn('id', $permissions)
                ->where('business_id', $businessId)
                ->get();
        } else {
            // Names case
            $perms = Permission::whereIn('name', $permissions)
                ->where('business_id', $businessId)
                ->get();
        }

        // Log invalid case
        if ($perms->isEmpty()) {
            Log::warning('No permissions found for sync', [
                'business_id' => $businessId,
                'input' => $permissions,
            ]);
        }

        // Sync permissions to user
        $user->syncPermissions($perms);

        // Forget Spatie cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->load('permissions');
    }
}
