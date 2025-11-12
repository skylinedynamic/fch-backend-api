<?php

namespace App\Services\FCH\Common\RolePermission;

use App\Models\Common\Permission;

class PermissionService
{
    /**
     * Get all permissions (id, name)
     */
    public function getAll()
    {
        return Permission::select('id', 'name')->orderBy('id')->get();
    }
}
