<?php

namespace App\Http\Controllers\Api\V1\FCH\Common;

use App\Http\Controllers\Controller;
use App\Services\FCH\Common\CommonService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class CommonController extends Controller
{
    public function __construct(protected CommonService $commonService) {}

    /**
     * Get All Users
     */
    public function getAllUsers(Request $request)
    {
        try {
            $users = $this->commonService->getAllUsers();

            return ApiResponse::success(
                $users,
                __('common.users_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to retrieve users.');
        }
    }
}
