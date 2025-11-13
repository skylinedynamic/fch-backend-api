<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use Throwable;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Permission\PermissionResource;
use App\Services\FCH\Common\RolePermission\PermissionService;

class PermissionController extends Controller
{

    /**
     * PermissionController constructor.
     *
     * @param PermissionService $permissionService
     */
    public function __construct(protected PermissionService $permissionService)
    {
    }

    /**
     * Get All
     */
    public function index()
    {
        try {
            $permissions = $this->permissionService->getAll();

            return ApiResponse::success(
                PermissionResource::collection($permissions),
                __('permission.permission_list_retrieved')
            );
        } catch (Throwable $e) {
            return ApiResponse::error(__($e->getMessage()), 400);
        }
    }
}
