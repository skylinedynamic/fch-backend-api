<?php

namespace App\Services\Global;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\Global\UserDetailResource;

class UserDetailService
{
    /**
     * Get the authenticated user's details.
     *
     * @return JsonResponse
     */
    public function getUserDetails(): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthenticated'));
        }

        // Eager-load relations needed for the resource
        $user->load([
            'roles' => function ($query) use ($user) {
                $query->where('partner_id', $user->partner_id);
            },
            'roles.permissions',
            'partner.subscription'
        ]);

        return ApiResponse::success([
            'user' => new UserDetailResource($user),
        ], __('user.user_retrieved'));
    }
}
