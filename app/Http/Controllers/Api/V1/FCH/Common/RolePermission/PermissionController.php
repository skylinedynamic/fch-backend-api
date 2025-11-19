<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\RolePermission;

use Throwable;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\RolePermission\PermissionRequest;
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
    public function index(PermissionRequest $request)
    {
        try {
            return $this->permissionService->getAll($request);
        } catch (Throwable $e) {
            return ApiResponse::error(__('permission.permission_list_error'), 500);
        }
    }
}
