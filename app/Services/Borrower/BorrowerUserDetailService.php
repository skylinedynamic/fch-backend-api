<?php

namespace App\Services\Borrower;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\Borrower\BorrowerUserDetailResource;

class BorrowerUserDetailService
{
    /**
     * Get the authenticated borrower user's details.
     *
     * @return JsonResponse
     */
    public function getUserDetails(): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthenticated'));
        }

        // See AuthService::login() for why '.phases' was dropped.
        $user->load(['entity.subscription']);

        return ApiResponse::success([
            'user' => new BorrowerUserDetailResource($user),
        ], __('user.user_retrieved'));
    }
}
